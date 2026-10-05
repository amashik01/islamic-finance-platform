<?php

namespace App\Services\Murabaha;

use App\Enums\ContractStatus;
use App\Enums\EntryDirection as D;
use App\Enums\LedgerAccountType as A;
use App\Enums\MurabahaStage as Stage;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\SettlementItemType as Item;
use App\Enums\SettlementStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\MurabahaContract;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Receivable;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Murabaha is a sale of an asset the seller owns and possesses:
 * request -> verification -> purchase -> ownership -> possession (qabd) -> sale -> receivable -> payments -> settlement.
 * Each step is enforced in order; selling before possession is impossible.
 */
class MurabahaService
{
    private const ORDER = [Stage::Requested, Stage::Verified, Stage::Purchased, Stage::Owned, Stage::Possessed, Stage::Sold, Stage::Settled];

    public function __construct(private LedgerService $ledger, private MurabahaSaleCalculator $calc, private AuditLogger $audit) {}

    public function verifySupplierAndAsset(MurabahaContract $m, User $by): MurabahaContract
    {
        if ($m->assets()->count() === 0) {
            throw new FinancialException('Identify at least one asset and its supplier before verification.');
        }

        return $this->advance($m, Stage::Verified, $by);
    }

    public function recordPurchase(MurabahaContract $m, Money $amount, string $invoiceReference, Carbon $on, User $by): MurabahaContract
    {
        if ($amount->minor !== $m->purchase_cost) {
            throw new FinancialException('The purchase amount must equal the approved purchase cost.');
        }
        $m->purchase()->updateOrCreate([], ['amount' => $amount->minor, 'purchased_on' => $on, 'invoice_reference' => $invoiceReference]);

        return $this->advance($m, Stage::Purchased, $by);
    }

    public function recordOwnership(MurabahaContract $m, Carbon $on, User $by): MurabahaContract
    {
        $m->purchase()->firstOrFail()->update(['ownership_acquired_on' => $on]);

        return $this->advance($m, Stage::Owned, $by);
    }

    public function recordPossession(MurabahaContract $m, Carbon $on, string $notes, User $by): MurabahaContract
    {
        $m->purchase()->firstOrFail()->update(['possession_on' => $on, 'possession_notes' => $notes]);

        return $this->advance($m, Stage::Possessed, $by);
    }

    /** Executes the Murabaha sale: creates the sale record, the receivable and a fixed payment schedule. */
    public function executeSale(MurabahaContract $m, Carbon $soldOn, Carbon $firstDueDate, User $by): Receivable
    {
        return DB::transaction(function () use ($m, $soldOn, $firstDueDate, $by) {
            $m = MurabahaContract::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $purchase = $m->purchase;
            if ($m->stage !== Stage::Possessed || ! $purchase?->possession_on || ! $purchase->ownership_acquired_on) {
                throw new FinancialException('The asset must be owned and in possession before it can be sold.');
            }
            $cur = $m->contract->currency;
            $price = $this->calc->salePrice(Money::minor($m->purchase_cost, $cur), Money::minor($m->sale_profit, $cur));
            if ($price->minor !== $m->sale_price) {
                throw new FinancialException('The sale price does not match cost plus sale profit.');
            }

            $sale = $m->sale()->create(['purchase_cost' => $m->purchase_cost, 'sale_profit' => $m->sale_profit, 'sale_price' => $price->minor, 'sold_on' => $soldOn]);
            $receivable = $sale->receivable()->forceCreate(['murabaha_sale_id' => $sale->id, 'business_id' => $m->contract->project->business_id, 'total_amount' => $price->minor, 'status' => PaymentStatus::Scheduled]);
            foreach ($this->calc->installments($price, $m->installments_count) as $n => $amount) {
                $receivable->schedules()->forceCreate(['receivable_id' => $receivable->id, 'sequence' => $n + 1, 'due_date' => $firstDueDate->copy()->addMonths($n)->toDateString(), 'amount' => $amount->minor, 'status' => PaymentStatus::Scheduled]);
            }
            $this->advance($m, Stage::Sold, $by);
            $m->contract->forceFill(['status' => ContractStatus::Active, 'start_date' => $soldOn])->save();
            $m->contract->project->forceFill(['status' => ProjectStatus::Active])->save();

            return $receivable;
        });
    }

    /** Records a buyer payment, applies it to installments oldest-first, and settles when fully paid. */
    public function recordPayment(Receivable $receivable, Money $amount, string $idempotencyKey, Carbon $paidOn, ?User $by = null): Payment
    {
        return DB::transaction(function () use ($receivable, $amount, $idempotencyKey, $paidOn, $by) {
            if ($existing = Payment::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $r = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            if (! $amount->isPositive()) {
                throw new FinancialException('Enter a payment amount greater than zero.');
            }
            if ($amount->minor > $r->outstanding()) {
                throw new FinancialException('The payment exceeds the outstanding amount of '.Money::minor($r->outstanding(), $amount->currency)->format().'.');
            }

            $contract = $r->sale->murabahaContract->contract;
            $tx = $this->ledger->post(TransactionType::MurabahaPayment, [
                ['account' => $this->ledger->systemAccount(A::PlatformCash, $amount->currency), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::ProjectFunds, $amount->currency, $contract->project_id), 'direction' => D::Credit, 'amount' => $amount],
            ], 'murabaha-payment:'.$idempotencyKey, ['project_id' => $contract->project_id, 'description' => 'Murabaha payment — '.$contract->contract_number, 'created_by' => $by?->id]);

            $payment = Payment::forceCreate(['receivable_id' => $r->id, 'amount' => $amount->minor, 'reference' => 'PAY-'.strtoupper(Str::random(8)), 'idempotency_key' => $idempotencyKey, 'transaction_id' => $tx->id, 'paid_on' => $paidOn]);

            $left = $amount->minor;
            foreach ($r->schedules()->orderBy('sequence')->lockForUpdate()->get() as $row) {
                if ($left === 0) {
                    break;
                }
                $apply = min($left, $row->amount - $row->paid_amount);
                if ($apply <= 0) {
                    continue;
                }
                $row->forceFill(['paid_amount' => $row->paid_amount + $apply, 'status' => $row->paid_amount + $apply >= $row->amount ? PaymentStatus::Paid : PaymentStatus::Partial])->save();
                $payment->forceFill(['payment_schedule_id' => $payment->payment_schedule_id ?? $row->id])->save();
                $left -= $apply;
            }

            $paid = $r->paid_amount + $amount->minor;
            $r->forceFill(['paid_amount' => $paid, 'status' => $paid >= $r->total_amount ? PaymentStatus::Paid : PaymentStatus::Partial])->save();
            if ($paid >= $r->total_amount) {
                $this->settle($r, $contract);
            }

            return $payment;
        });
    }

    /** Marks installments past due as overdue; returns how many were flagged. */
    public function markOverdue(?Carbon $asOf = null): int
    {
        $asOf ??= now();

        return PaymentSchedule::whereIn('status', [PaymentStatus::Scheduled, PaymentStatus::Partial])
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->update(['status' => PaymentStatus::Overdue]);
    }

    private function settle(Receivable $r, Contract $contract): void
    {
        $m = $r->sale->murabahaContract;
        $cur = $contract->currency;
        $s = new Settlement(['reference' => 'STL-'.strtoupper(Str::random(8)), 'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'currency' => $cur]);
        $s->forceFill(['status' => SettlementStatus::Posted, 'posted_at' => now()])->save();
        // Kept as separate concepts: cost recovered vs Murabaha sale profit (never "interest").
        $s->items()->forceCreate(['settlement_id' => $s->id, 'item_type' => Item::Principal, 'amount' => $r->sale->purchase_cost]);
        $s->items()->forceCreate(['settlement_id' => $s->id, 'item_type' => Item::MurabahaSaleProfit, 'amount' => $r->sale->sale_profit]);
        $this->advance($m, Stage::Settled, null);
        $contract->forceFill(['status' => ContractStatus::Completed])->save();
        $contract->project->forceFill(['status' => ProjectStatus::Completed])->save();
        $this->audit->record('murabaha.settled', $s);
    }

    private function advance(MurabahaContract $m, Stage $to, ?User $by): MurabahaContract
    {
        $from = array_search($m->stage, self::ORDER, true);
        $target = array_search($to, self::ORDER, true);
        if ($target !== $from + 1) {
            throw new FinancialException('Murabaha steps must be completed in order. Next step: '.(self::ORDER[$from + 1] ?? $m->stage)->label().'.');
        }
        $old = $m->stage->value;
        $m->forceFill(['stage' => $to])->save();
        $this->audit->record('murabaha.'.strtolower($to->value), $m->contract, ['stage' => $old], ['stage' => $to->value]);

        return $m;
    }
}
