<?php

namespace App\Services\Contract;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EntryDirection as D;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\MusharakahCapitalContribution;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the business partner's capital in a Musharakah as a real financial fact:
 *   Dr Platform cash / Cr Project funds   (type MUSHARAKAH_CAPITAL)
 * plus a contribution record tied to that ledger transaction. Nothing may claim the business contributed capital
 * unless this record and its transaction exist.
 */
class MusharakahCapitalService
{
    public function __construct(private LedgerService $ledger, private ContractLifecycle $lifecycle, private AuditLogger $audit) {}

    public function recordBusinessContribution(Contract $contract, Money $amount, string $idempotencyKey, User $by, ?Carbon $receivedOn = null): MusharakahCapitalContribution
    {
        $hash = IdempotencyGuard::hash(['op' => 'musharakah_capital', 'contract' => $contract->id, 'amount' => $amount->minor]);
        try {
            return $this->record($contract, $amount, $idempotencyKey, $by, $receivedOn ?? now(), $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = MusharakahCapitalContribution::where('idempotency_key', $idempotencyKey)->first()
                ?? throw new FinancialException('A capital contribution has already been recorded for this contract.');
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    private function record(Contract $contract, Money $amount, string $key, User $by, Carbon $on, string $hash): MusharakahCapitalContribution
    {
        return DB::transaction(function () use ($contract, $amount, $key, $by, $on, $hash) {
            if ($existing = MusharakahCapitalContribution::where('idempotency_key', $key)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            // Lock order: project -> contract.
            $project = Project::whereKey($contract->project_id)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($contract->contract_type !== ContractType::Musharakah) {
                throw new FinancialException('Only a Musharakah contract has a business capital contribution.');
            }
            Currency::require($contract->currency, 'The contract');
            $terms = $contract->musharakah ?? throw new FinancialException('Musharakah terms are missing.');
            if ($contract->status !== ContractStatus::Approved) {
                throw new FinancialException('Business capital can only be recorded for an approved contract that has not started.');
            }
            if ($contract->musharakahContribution()->exists()) {
                throw new FinancialException('The business capital for this contract has already been recorded.');
            }
            // Capital consistency: investor + business = total agreed capital, and the amount equals the agreed contribution.
            if ((int) $terms->investor_contribution + (int) $terms->business_contribution !== (int) $terms->total_capital) {
                throw new FinancialException('The contract terms are inconsistent: investor and business capital do not add up to the total capital.');
            }
            if ($amount->minor !== (int) $terms->business_contribution) {
                throw new FinancialException('The amount must equal the agreed business contribution of '.Money::minor($terms->business_contribution)->format().'.');
            }

            $tx = $this->ledger->post(TransactionType::MusharakahCapital, [
                ['account' => $this->ledger->systemAccount(A::CustodyCash), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::BusinessCapital, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $amount],
            ], 'musharakah-capital:'.$contract->id, ['project_id' => $contract->project_id, 'description' => 'Musharakah business capital — '.$contract->contract_number, 'created_by' => $by->id]);

            $c = new MusharakahCapitalContribution([
                'contract_id' => $contract->id, 'business_id' => $project->business_id, 'amount' => $amount->minor, 'currency' => Currency::CODE,
                'reference' => 'MCC-'.strtoupper(Str::random(8)), 'received_on' => $on, 'idempotency_key' => $key, 'recorded_by' => $by->id,
            ]);
            $c->forceFill(['transaction_id' => $tx->id, 'request_hash' => $hash])->save();
            $this->audit->record('musharakah.capital_received', $contract, null, ['amount' => $amount->minor, 'transaction_id' => $tx->id]);

            $contract->unsetRelation('musharakahContribution');
            $this->lifecycle->activateIfFunded($project);   // may complete the activation

            return $c;
        }, 3);
    }
}
