<?php

namespace App\Services\Contract;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EntryDirection as D;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\CapitalDeployment;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Models\VentureRemittance;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cash received from the business, in two explicit components (never one undifferentiated "profit" figure):
 *   CAPITAL_RETURN     Dr CustodyCash / Cr VentureCapital  - capital value recovered (type VENTURE_CAPITAL_RETURN)
 *   INTERIM_PROCEEDS   Dr CustodyCash / Cr ProjectFunds    - an advance held pending the final determination (type INTERIM_PROCEEDS)
 * Interim proceeds are NOT final profit (rules MUD-ADVANCES, MUS-ADVANCES): the final result is determined at settlement.
 * Takes the project and contract locks and re-checks the status inside them, so a remittance cannot race a settlement.
 */
class VentureRemittanceService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit) {}

    public function record(Contract $contract, string $component, Money $amount, string $receiptReference, string $key, User $by, ?Carbon $on = null): VentureRemittance
    {
        if (! in_array($component, [VentureRemittance::CAPITAL_RETURN, VentureRemittance::INTERIM_PROCEEDS], true)) {
            throw new FinancialException('Choose whether the remittance is returned capital or interim proceeds.');
        }
        $hash = IdempotencyGuard::hash(['op' => 'remit', 'contract' => $contract->id, 'component' => $component, 'amount' => $amount->minor, 'receipt' => $receiptReference]);
        try {
            return $this->post($contract, $component, $amount, $receiptReference, $key, $by, $on ?? now(), $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = VentureRemittance::where('idempotency_key', $key)->firstOrFail();
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    private function post(Contract $contract, string $component, Money $amount, string $receipt, string $key, User $by, Carbon $on, string $hash): VentureRemittance
    {
        return DB::transaction(function () use ($contract, $component, $amount, $receipt, $key, $by, $on, $hash) {
            if ($existing = VentureRemittance::where('idempotency_key', $key)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            Project::whereKey($contract->project_id)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            Currency::require($contract->currency, 'The contract');
            Currency::require($amount->currency, 'The remittance');
            if (! in_array($contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
                throw new FinancialException('Only Mudarabah and Musharakah contracts take a business remittance.');
            }
            if ($contract->status !== ContractStatus::Active) {
                throw new FinancialException('A remittance can only be recorded for an active contract.');
            }
            if (! $amount->isPositive()) {
                throw new FinancialException('Enter a remittance amount greater than zero.');
            }
            if (! CapitalDeployment::where('contract_id', $contract->id)->exists()) {
                throw new FinancialException('The capital has not been deployed to the venture yet, so nothing can be remitted back.');
            }

            $capital = $component === VentureRemittance::CAPITAL_RETURN;
            $tx = $this->ledger->post($capital ? TransactionType::VentureCapitalReturn : TransactionType::InterimProceeds, [
                ['account' => $this->ledger->systemAccount(A::CustodyCash), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $capital
                    ? $this->ledger->systemAccount(A::VentureCapital, Currency::CODE, $contract->project_id)
                    : $this->ledger->systemAccount(A::ProjectFunds, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $amount],
            ], $key, ['project_id' => $contract->project_id, 'description' => ($capital ? 'Capital returned by the business — ' : 'Interim proceeds from the business — ').$contract->contract_number, 'created_by' => $by->id]);

            $r = new VentureRemittance(['contract_id' => $contract->id, 'component' => $component, 'amount' => $amount->minor, 'currency' => Currency::CODE,
                'reference' => 'REM-'.strtoupper(Str::random(8)), 'receipt_reference' => $receipt ?: null, 'received_on' => $on, 'idempotency_key' => $key, 'recorded_by' => $by->id]);
            $r->forceFill(['transaction_id' => $tx->id, 'request_hash' => $hash])->save();
            $this->audit->record('contract.remittance_recorded', $contract, null, ['component' => $component, 'amount' => $amount->minor, 'transaction_id' => $tx->id]);

            return $r;
        }, 3);
    }
}
