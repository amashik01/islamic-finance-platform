<?php

namespace App\Services\Settlement;

use App\Enums\EntryDirection as D;
use App\Enums\LedgerAccountType as A;
use App\Enums\ManagerRecoveryStatus as S;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\ManagerRecovery;
use App\Models\SettlementItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Ledger\LedgerService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Mudarabah manager recovery, as an evidence-based lifecycle (rules MUD-MUDARIB-FAULT, MUD-LOSS-RABB):
 *   SUSPECTED -> UNDER_REVIEW -> FAULT_ESTABLISHED -> LIABILITY_RECOGNIZED -> (partial) -> RECOVERED   | WRITTEN_OFF
 * The first three states are allegations or findings and NEVER create a ledger asset. Money is accepted only once the
 * liability is recognised. A recovery is compensation for the loss, not investment profit: it goes to the investors who
 * bore the loss, not through the profit ratio. Who decides fault, and on what burden of proof, is an open Shariah and
 * legal question (docs/shariah/OPEN_SCHOLAR_QUESTIONS.md); this service records the decision and its evidence.
 */
class ManagerRecoveryService
{
    private const NEXT = [
        'SUSPECTED' => ['UNDER_REVIEW', 'WRITTEN_OFF'],
        'UNDER_REVIEW' => ['FAULT_ESTABLISHED', 'WRITTEN_OFF'],
        'FAULT_ESTABLISHED' => ['LIABILITY_RECOGNIZED', 'WRITTEN_OFF'],
        'LIABILITY_RECOGNIZED' => ['PARTIAL', 'RECOVERED', 'WRITTEN_OFF'],
        'PARTIAL' => ['PARTIAL', 'RECOVERED', 'WRITTEN_OFF'],
    ];

    public function __construct(private LedgerService $ledger, private WalletService $wallets, private AuditLogger $audit) {}

    public function startReview(ManagerRecovery $r, User $by): ManagerRecovery
    {
        return $this->move($r, S::UnderReview, $by, [], 'recovery.review_started');
    }

    public function establishFault(ManagerRecovery $r, User $by, string $evidence): ManagerRecovery
    {
        if (mb_strlen(trim($evidence)) < 30) {
            throw new FinancialException('Record the evidence for the finding of negligence, misconduct or breach (at least 30 characters).');
        }

        return $this->move($r, S::FaultEstablished, $by, ['fault_evidence' => trim($evidence), 'fault_established_by' => $by->id, 'fault_established_at' => now()], 'recovery.fault_established', $evidence);
    }

    /** @param  Money|null  $amount  the liability recognised (never more than the claimed loss) */
    public function recognizeLiability(ManagerRecovery $r, User $by, ?Money $amount = null): ManagerRecovery
    {
        $claimed = (int) ($r->claimed_amount ?? $r->amount);
        $amount ??= Money::minor($claimed);
        if (! $amount->isPositive() || $amount->minor > $claimed) {
            throw new FinancialException('The recognised liability cannot exceed the claimed loss of '.Money::minor($claimed)->format().'.');
        }

        return $this->move($r, S::LiabilityRecognized, $by, ['amount' => $amount->minor, 'liability_recognized_by' => $by->id, 'liability_recognized_at' => now()], 'recovery.liability_recognized');
    }

    public function writeOff(ManagerRecovery $r, User $by, string $reason): ManagerRecovery
    {
        if (blank($reason)) {
            throw new FinancialException('Give a reason for closing the claim without recovery.');
        }

        return $this->move($r, S::WrittenOff, $by, [], 'recovery.written_off', $reason);
    }

    /** Cash received from the manager for a RECOGNISED liability, distributed to the investors who bore the loss. */
    public function receive(ManagerRecovery $recovery, Money $amount, string $key, User $by): ManagerRecovery
    {
        return DB::transaction(function () use ($recovery, $amount, $key, $by) {
            $r = ManagerRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if ($tx = \App\Models\Transaction::where('idempotency_key', $key)->first()) {
                // Receipt, distributions and the status change commit together, so an existing receipt means all of it happened.
                if ((int) $tx->amount !== $amount->minor) {
                    throw new \App\Exceptions\IdempotencyConflictException('This idempotency key was already used for a different recovery amount.');
                }

                return $r;
            }
            if (! in_array($r->status, [S::LiabilityRecognized, S::Partial], true)) {
                throw new FinancialException('A recovery can only be received once the manager\'s liability has been recognised.');
            }
            Currency::require($amount->currency, 'The recovery');
            if (! $amount->isPositive() || $amount->minor > $r->outstanding()) {
                throw new FinancialException('The amount exceeds the outstanding recoverable amount of '.Money::minor($r->outstanding())->format().'.');
            }
            $pid = $r->contract->project_id;
            $this->ledger->post(TransactionType::RecoveryReceipt, [
                ['account' => $this->ledger->systemAccount(A::CustodyCash), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::ProjectFunds, Currency::CODE, $pid), 'direction' => D::Credit, 'amount' => $amount],
            ], $key, ['project_id' => $pid, 'description' => 'Manager recovery received — '.$r->contract->contract_number, 'created_by' => $by->id]);

            // Pass it on to the investors who bore the loss, pro rata to their loss.
            $items = SettlementItem::where('settlement_id', $r->settlement_id)->where('item_type', \App\Enums\SettlementItemType::Adjustment->value)->orderBy('id')->get();
            $shares = $amount->allocate($items->map(fn ($i) => abs((int) $i->amount))->all());
            foreach ($items as $n => $item) {
                if (! $shares[$n]->isPositive()) {
                    continue;
                }
                $wallet = $this->wallets->walletFor($item->user);
                $this->ledger->post(TransactionType::RecoveryDistribution, [
                    ['account' => $this->ledger->systemAccount(A::ProjectFunds, Currency::CODE, $pid), 'direction' => D::Debit, 'amount' => $shares[$n]],
                    ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $shares[$n]],
                ], $key.':dist:'.$item->id, ['project_id' => $pid, 'user_id' => $item->user_id, 'investment_id' => $item->investment_id, 'description' => 'Recovery distributed — '.$r->contract->contract_number, 'created_by' => $by->id]);
            }
            $total = $r->recovered_amount + $amount->minor;
            $r->forceFill(['recovered_amount' => $total, 'distributed_amount' => $total, 'status' => $total >= $r->amount ? S::Recovered : S::Partial])->save();
            $this->audit->record('recovery.received', $r, ['recovered_amount' => $r->recovered_amount - $amount->minor], ['recovered_amount' => $total], null);

            return $r;
        }, 3);
    }

    private function move(ManagerRecovery $recovery, S $to, User $by, array $fields, string $audit, ?string $reason = null): ManagerRecovery
    {
        if (! $by->can('settlements.manage')) {
            throw new FinancialException('You are not allowed to manage manager recoveries.');
        }

        return DB::transaction(function () use ($recovery, $to, $fields, $audit, $reason) {
            $r = ManagerRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to->value, self::NEXT[$r->status->value] ?? [], true)) {
                throw new FinancialException('A recovery cannot move from '.$r->status->label().' to '.$to->label().'.');
            }
            $old = $r->status->value;
            $r->forceFill($fields + ['status' => $to])->save();
            $this->audit->record($audit, $r, ['status' => $old], ['status' => $to->value], $reason);

            return $r;
        });
    }
}
