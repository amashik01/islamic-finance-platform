<?php

namespace App\Services\Contract;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EntryDirection as D;
use App\Enums\InvestmentStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\CapitalDeployment;
use App\Models\Contract;
use App\Models\Investment;
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
 * Records the real delivery of participant capital to the business: Dr VentureCapital / Cr CustodyCash.
 * Cash leaves custody only here, once per contract, only for an ACTIVE contract and for exactly the capital the
 * participants committed (investor capital + the business contribution in a Musharakah).
 * Rules: MUD-CAPITAL-DELIVERY, MUS-CAPITAL-TIMING. Funding is not deployment; deployment is not profit.
 */
class CapitalDeploymentService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit) {}

    /** The capital the participants have committed to the venture of this contract. */
    public function ventureCapital(Contract $contract): Money
    {
        $investors = (int) Investment::where('project_id', $contract->project_id)->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active])->sum('amount');
        $business = $contract->contract_type === ContractType::Musharakah ? (int) ($contract->musharakahContribution?->amount ?? 0) : 0;

        return Money::minor($investors + $business);
    }

    public function deploy(Contract $contract, Money $amount, string $deliveryReference, string $key, User $by, ?Carbon $on = null): CapitalDeployment
    {
        $hash = IdempotencyGuard::hash(['op' => 'deploy', 'contract' => $contract->id, 'amount' => $amount->minor, 'delivery' => $deliveryReference]);
        try {
            return $this->post($contract, $amount, $deliveryReference, $key, $by, $on ?? now(), $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = CapitalDeployment::where('idempotency_key', $key)->orWhere('contract_id', $contract->id)->first();
            if ($existing && $existing->idempotency_key === $key) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            throw new FinancialException('The capital of this contract has already been deployed.');
        }
    }

    private function post(Contract $contract, Money $amount, string $deliveryReference, string $key, User $by, Carbon $on, string $hash): CapitalDeployment
    {
        return DB::transaction(function () use ($contract, $amount, $deliveryReference, $key, $by, $on, $hash) {
            if ($existing = CapitalDeployment::where('idempotency_key', $key)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            // Lock order: project -> contract -> ledger accounts.
            $project = Project::whereKey($contract->project_id)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            Currency::require($contract->currency, 'The contract');
            Currency::require($amount->currency, 'The deployment amount');
            if (! in_array($contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
                throw new FinancialException('Only a Mudarabah or Musharakah contract deploys capital to a venture.');
            }
            if ($contract->status !== ContractStatus::Active) {
                throw new FinancialException('Capital can only be deployed for an active contract.');
            }
            if (blank(trim($deliveryReference))) {
                throw new FinancialException('Enter the delivery reference of the actual transfer to the business.');
            }
            if (CapitalDeployment::where('contract_id', $contract->id)->exists()) {
                throw new FinancialException('The capital of this contract has already been deployed.');
            }
            $expected = $this->ventureCapital($contract);
            if (! $amount->isPositive() || $amount->minor !== $expected->minor) {
                throw new FinancialException('The deployment must equal the committed capital of '.$expected->format().'.');
            }

            $tx = $this->ledger->post(TransactionType::CapitalDeployment, [
                ['account' => $this->ledger->systemAccount(A::VentureCapital, Currency::CODE, $contract->project_id), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->ledger->systemAccount(A::CustodyCash), 'direction' => D::Credit, 'amount' => $amount],
            ], 'capital-deployment:'.$contract->id, ['project_id' => $contract->project_id, 'description' => 'Capital deployed to the venture — '.$contract->contract_number, 'created_by' => $by->id]);

            $d = new CapitalDeployment(['contract_id' => $contract->id, 'amount' => $amount->minor, 'currency' => Currency::CODE, 'reference' => 'DEP-'.strtoupper(Str::random(8)),
                'delivery_reference' => trim($deliveryReference), 'deployed_on' => $on, 'idempotency_key' => $key, 'recorded_by' => $by->id]);
            $d->forceFill(['transaction_id' => $tx->id, 'request_hash' => $hash])->save();
            $this->audit->record('contract.capital_deployed', $contract, null, ['amount' => $amount->minor, 'transaction_id' => $tx->id, 'delivery_reference' => trim($deliveryReference)]);

            return $d;
        }, 3);
    }
}
