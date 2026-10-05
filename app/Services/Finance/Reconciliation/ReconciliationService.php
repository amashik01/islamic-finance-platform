<?php

namespace App\Services\Finance\Reconciliation;

use App\Services\Finance\Reconciliation\Checks\AqdActivationIntegrity;
use App\Services\Finance\Reconciliation\Checks\AqdDocumentIntegrity;
use App\Services\Finance\Reconciliation\Checks\AqdTermIntegrity;
use App\Services\Finance\Reconciliation\Checks\ContractLifecycleIntegrity;
use App\Services\Finance\Reconciliation\Checks\CurrencyIntegrity;
use App\Services\Finance\Reconciliation\Checks\IdempotencyIntegrity;
use App\Services\Finance\Reconciliation\Checks\InvestmentIntegrity;
use App\Services\Finance\Reconciliation\Checks\InvestorContractLinkage;
use App\Services\Finance\Reconciliation\Checks\LedgerBalance;
use App\Services\Finance\Reconciliation\Checks\MurabahaReceivables;
use App\Services\Finance\Reconciliation\Checks\MurabahaSequenceIntegrity;
use App\Services\Finance\Reconciliation\Checks\ProjectFunding;
use App\Services\Finance\Reconciliation\Checks\SettlementIntegrity;
use App\Services\Finance\Reconciliation\Checks\WakalahIntegrity;
use App\Services\Finance\Reconciliation\Checks\WalletIntegrity;

/** Runs every critical, read-only financial invariant check. Never writes to the database. */
class ReconciliationService
{
    /** @return list<Check> */
    public function checks(): array
    {
        return [new CurrencyIntegrity, new LedgerBalance, new WalletIntegrity, new InvestmentIntegrity, new ProjectFunding, new ContractLifecycleIntegrity, new SettlementIntegrity, new MurabahaReceivables, new IdempotencyIntegrity,
            new AqdDocumentIntegrity, new AqdActivationIntegrity, new AqdTermIntegrity, new InvestorContractLinkage, new WakalahIntegrity, new MurabahaSequenceIntegrity];
    }

    /** @return list<CheckResult> */
    public function run(): array
    {
        return array_map(fn (Check $c) => $c->run(), $this->checks());
    }

    /** @param list<CheckResult> $results */
    public static function passed(array $results, bool $strict = false): bool
    {
        return collect($results)->every(fn (CheckResult $r) => $r->passed($strict));
    }
}
