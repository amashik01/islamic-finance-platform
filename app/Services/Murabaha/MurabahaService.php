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
use App\Enums\ContractDocumentKind;
use App\Enums\ContractDocumentStatus;
use App\Enums\WakalahRole;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\MurabahaPromise;
use App\Models\MurabahaContract;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Receivable;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Aqd\ContractGenerator;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Wakalah\WakalahService;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Currency;
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

    public function __construct(private LedgerService $ledger, private MurabahaSaleCalculator $calc, private AuditLogger $audit, private WakalahService $wakalah, private ContractGenerator $generator) {}

    /** A Wakil may perform only the acts its confirmed Wakalah grants; staff acting for the platform are not Wakils. */
    private function assertAuthority(MurabahaContract $m, User $by, WakalahRole $role, string $act): ?User
    {
        if (! $by->isWakil()) {
            return null;
        }
        $this->wakalah->assertMayAct($m->contract->project, $by, $role, $act);

        return $by;
    }

    /**
     * Records the promise (wa'd) that preceded the sale. A promise is not the sale: it creates no receivable and no price obligation.
     * A mutual promise is accepted only with an option for one or both parties (rule MUR-PROMISE).
     */
    public function recordPromise(MurabahaContract $m, string $type, string $promisor, ?string $optionHolder, ?string $conditions, User $by): MurabahaPromise
    {
        if (! in_array($type, ['UNILATERAL', 'BILATERAL_WITH_OPTION'], true)) {
            throw new FinancialException('A mutual promise without an option is not supported; choose a unilateral promise or a mutual promise with an option.');
        }
        if ($type === 'BILATERAL_WITH_OPTION' && blank($optionHolder)) {
            throw new FinancialException('A mutual promise needs an option for one or both parties.');
        }
        if (! in_array($m->stage, [Stage::Requested, Stage::Verified], true)) {
            throw new FinancialException('The promise is recorded before the asset is purchased.');
        }
        if ($m->promise()->exists()) {
            throw new FinancialException('A promise is already recorded for this contract.');
        }
        $promise = $m->promise()->create(['promise_type' => $type, 'promisor' => $promisor, 'option_holder' => $optionHolder, 'conditions' => $conditions, 'recorded_by' => $by->id, 'recorded_at' => now()]);
        $this->audit->record('murabaha.promise_recorded', $m->contract, null, ['type' => $type, 'promisor' => $promisor]);

        return $promise;
    }

    /**
     * The seller confirms it has borne the risk of the asset since acquiring it, for at least the agreed period before the sale.
     * The minimum period is a policy choice REQUIRING QUALIFIED SHARIAH REVIEW.
     */
    public function confirmRiskBorne(MurabahaContract $m, Carbon $on, string $notes, User $by): MurabahaContract
    {
        $p = $m->purchase;
        if ($m->stage !== Stage::Possessed || ! $p?->possession_on) {
            throw new FinancialException('The asset must be in possession before the seller\'s risk can be confirmed.');
        }
        if (trim($notes) === '') {
            throw new FinancialException('Describe how the seller bore the risk of the asset (insurance, storage, custody).');
        }
        if ($on->copy()->startOfDay()->isFuture()) {
            throw new FinancialException('The risk confirmation date cannot be in the future.');
        }
        $days = (int) (($m->contract->aqd_terms ?? [])['risk_bearing_days'] ?? 0);
        if ($on->copy()->startOfDay()->diffInDays($p->possession_on->copy()->startOfDay(), false) > -$days) {
            throw new FinancialException("The seller must bear the asset's risk for at least {$days} day(s) after taking possession before the sale.");
        }
        $p->forceFill(['risk_confirmed_on' => $on, 'risk_confirmed_by' => $by->id, 'risk_notes' => $notes])->save();
        $this->audit->record('murabaha.risk_confirmed', $m->contract, null, ['on' => $on->toDateString()]);

        return $m;
    }

    /** Prepares the sale agreement for signature (after possession and risk confirmation). */
    public function prepareSaleAgreement(MurabahaContract $m, User $by): ContractDocument
    {
        return $this->generator->murabahaSale($m, $by);
    }

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
        Currency::require($m->contract->currency, 'The contract');
        $acting = $this->assertAuthority($m, $by, WakalahRole::Purchase, 'pay_supplier');
        // The asset is bought only under an executed, Shariah-reviewed master agreement (pre-engine contracts are LEGACY and exempt).
        if ($m->contract->aqd_form_version !== null && ! ContractDocument::where('contract_id', $m->contract_id)->where('kind', ContractDocumentKind::MasterAqd->value)->where('status', ContractDocumentStatus::Executed->value)->exists()) {
            throw new FinancialException('The Murabaha master agreement has not been executed; the asset cannot be purchased yet.');
        }
        if (($m->contract->aqd_terms['use_promise'] ?? false) && ! $m->promise()->exists()) {
            throw new FinancialException('The promise (wa\'d) used by this contract has not been recorded.');
        }

        return DB::transaction(function () use ($m, $amount, $invoiceReference, $on, $by, $acting) {
            $m->purchase()->updateOrCreate([], ['amount' => $amount->minor, 'purchased_on' => $on, 'invoice_reference' => $invoiceReference, 'acting_wakil_id' => $acting?->id]);
            // Asset acquisition: cash leaves, an owned asset (inventory) appears at cost.
            $this->ledger->post(TransactionType::MurabahaPurchase, [
                ['account' => $this->ledger->systemAccount(A::MurabahaInventory, Currency::CODE, $m->contract->project_id), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::PlatformCash), 'direction' => D::Credit, 'amount' => $amount],
            ], 'murabaha-purchase:'.$m->id, ['project_id' => $m->contract->project_id, 'description' => 'Murabaha asset purchase — '.$m->contract->contract_number, 'created_by' => $by->id]);

            return $this->advance($m, Stage::Purchased, $by);
        });
    }

    public function recordOwnership(MurabahaContract $m, Carbon $on, User $by): MurabahaContract
    {
        $this->assertAuthority($m, $by, WakalahRole::AssetAcquisition, 'record_title');
        $m->purchase()->firstOrFail()->update(['ownership_acquired_on' => $on]);

        return $this->advance($m, Stage::Owned, $by);
    }

    public function recordPossession(MurabahaContract $m, Carbon $on, string $notes, User $by, string $qabdType = 'ACTUAL'): MurabahaContract
    {
        $this->assertAuthority($m, $by, WakalahRole::DeliveryQabd, 'take_delivery');
        if (! in_array($qabdType, ['ACTUAL', 'CONSTRUCTIVE'], true)) {
            throw new FinancialException('Possession is either actual or constructive.');
        }
        $m->purchase()->firstOrFail()->update(['possession_on' => $on, 'possession_notes' => $notes, 'qabd_type' => $qabdType]);

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
            if (! $purchase->risk_confirmed_on) {
                throw new FinancialException('The seller\'s risk of the asset has not been confirmed; the sale cannot be executed.');
            }
            if (($m->contract->aqd_terms['use_promise'] ?? false) && ! $m->promise()->exists()) {
                throw new FinancialException('The promise (wa\'d) used by this contract has not been recorded.');
            }
            $saleDoc = ContractDocument::where('contract_id', $m->contract_id)->where('kind', ContractDocumentKind::MurabahaSale->value)->where('status', ContractDocumentStatus::Executed->value)->latest('id')->first();
            if (! $saleDoc || ! $saleDoc->hashIntact()) {
                throw new FinancialException('The Murabaha sale agreement has not been executed by both parties; no sale or receivable can be created before it is.');
            }
            Currency::require($m->contract->currency, 'The contract');
            Currency::require($m->contract->project->currency, 'The project');
            $price = $this->calc->salePrice(Money::minor($m->purchase_cost), Money::minor($m->sale_profit));
            if ($price->minor !== $m->sale_price) {
                throw new FinancialException('The sale price does not match cost plus sale profit.');
            }

            $sale = $m->sale()->create(['sale_document_id' => $saleDoc->id, 'purchase_cost' => $m->purchase_cost, 'sale_profit' => $m->sale_profit, 'sale_price' => $price->minor, 'sold_on' => $soldOn]);
            $receivable = $sale->receivable()->forceCreate(['murabaha_sale_id' => $sale->id, 'business_id' => $m->contract->project->business_id, 'total_amount' => $price->minor, 'status' => PaymentStatus::Scheduled]);
            foreach ($this->calc->installments($price, $m->installments_count) as $n => $amount) {
                $receivable->schedules()->forceCreate(['receivable_id' => $receivable->id, 'sequence' => $n + 1, 'due_date' => $firstDueDate->copy()->addMonths($n)->toDateString(), 'amount' => $amount->minor, 'status' => PaymentStatus::Scheduled]);
            }
            // The sale: the asset leaves inventory at cost; a receivable is created for the full sale price;
            // the Murabaha sale profit (price - cost) is recognised separately. Never "interest".
            $pid = $m->contract->project_id;
            $this->ledger->post(TransactionType::MurabahaSale, [
                ['account' => $this->ledger->systemAccount(A::MurabahaReceivable, Currency::CODE, $pid), 'direction' => D::Debit, 'amount' => $price],
                ['account' => $this->ledger->systemAccount(A::MurabahaInventory, Currency::CODE, $pid), 'direction' => D::Credit, 'amount' => Money::minor($m->purchase_cost)],
                ['account' => $this->ledger->systemAccount(A::MurabahaSaleProfit, Currency::CODE, $pid), 'direction' => D::Credit, 'amount' => Money::minor($m->sale_profit)],
            ], 'murabaha-sale:'.$m->id, ['project_id' => $pid, 'description' => 'Murabaha sale — '.$m->contract->contract_number, 'created_by' => $by->id]);
            $this->advance($m, Stage::Sold, $by);
            $m->contract->forceFill(['status' => ContractStatus::Active, 'start_date' => $soldOn])->save();
            $m->contract->project->forceFill(['status' => ProjectStatus::Active])->save();

            return $receivable;
        });
    }

    /** Records a buyer payment, applies it to installments oldest-first, and settles when fully paid. */
    public function recordPayment(Receivable $receivable, Money $amount, string $idempotencyKey, Carbon $paidOn, ?User $by = null): Payment
    {
        $hash = IdempotencyGuard::hash(['op' => 'murabaha_payment', 'receivable' => $receivable->id, 'amount' => $amount->minor, 'paid_on' => $paidOn->toDateString()]);
        try {
            return $this->apply($receivable, $amount, $idempotencyKey, $paidOn, $by, $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->firstOrFail();
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    private function apply(Receivable $receivable, Money $amount, string $idempotencyKey, Carbon $paidOn, ?User $by, string $hash): Payment
    {
        return DB::transaction(function () use ($receivable, $amount, $idempotencyKey, $paidOn, $by, $hash) {
            if ($existing = Payment::where('idempotency_key', $idempotencyKey)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

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
            Currency::require($contract->currency, 'The contract');
            $tx = $this->ledger->post(TransactionType::MurabahaPayment, [
                ['account' => $this->ledger->systemAccount(A::PlatformCash), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::MurabahaReceivable, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $amount],
            ], 'murabaha-payment:'.$idempotencyKey, ['project_id' => $contract->project_id, 'description' => 'Murabaha payment — '.$contract->contract_number, 'created_by' => $by?->id]);

            $payment = Payment::forceCreate(['receivable_id' => $r->id, 'amount' => $amount->minor, 'reference' => 'PAY-'.strtoupper(Str::random(8)), 'idempotency_key' => $idempotencyKey, 'transaction_id' => $tx->id, 'paid_on' => $paidOn, 'request_hash' => $hash]);

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
        Currency::require($contract->currency, 'The contract');
        $s = new Settlement(['reference' => 'STL-'.strtoupper(Str::random(8)), 'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'currency' => Currency::CODE]);
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
