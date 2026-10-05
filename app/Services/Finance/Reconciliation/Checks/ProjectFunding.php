<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/**
 * Venture capital lifecycle. For every Mudarabah / Musharakah project, each account balance must equal what the
 * recorded business events explain, and a completed project must leave nothing behind:
 *
 *   investor capital committed  I = live investments            business capital B = recorded Musharakah contribution
 *   deployed                    D = capital deployment          returned Rc, interim proceeds Rp = venture remittances
 *   loss recognised             W = LOSS_RECOGNITION            distributed Pi + Pb (profit), recoveries received/distributed
 *
 *   VentureCapital = D - Rc - W                  (0 once completed)
 *   ProjectFunds   = Rp + recoveries - profit distributed - recoveries distributed   (0 once completed and recoveries closed)
 *   BusinessCapital= B - business loss - business capital returned                    (0 once completed)
 *   Clearing       = 0, always (it is not a loss account)
 *   CustodyCash    >= 0, and no non-Murabaha event touches PlatformCash (platform money)
 *
 * Projects created under the earlier funding design (PROJECT_FUNDING / CAPITAL_RELEASE entries) are LEGACY: they are
 * reported as a warning and not judged against these equations; their history is never rewritten.
 */
class ProjectFunding extends Check
{
    private const LIVE = ['CONFIRMED', 'ACTIVE', 'COMPLETED'];

    private const LEGACY_TYPES = ['PROJECT_FUNDING', 'CAPITAL_RELEASE', 'BUSINESS_REMITTANCE', 'CAPITAL_LOSS'];

    private array $legacy = [];

    public function name(): string
    {
        return 'Project Funding';
    }

    private function bal(string $type, int $projectId): int
    {
        return (int) DB::table('ledger_accounts')->where('type', $type)->where('project_id', $projectId)->value('balance');
    }

    private function sumTx(string $type, int $projectId): int
    {
        return (int) DB::table('transactions')->where('type', $type)->where('project_id', $projectId)->where('status', '!=', 'REVERSED')->sum('amount');
    }

    protected function warnings(): array
    {
        $this->legacy = DB::table('transactions')->whereIn('type', self::LEGACY_TYPES)->whereNotNull('project_id')->distinct()->pluck('project_id')->all();

        return array_map(fn ($id) => "Project #$id uses the LEGACY funding ledger model; it is not checked against the venture-capital equations.", $this->legacy);
    }

    protected function errors(): array
    {
        $e = [];
        $this->warnings();   // loads $this->legacy

        // Global rules ---------------------------------------------------------------------------------------------
        foreach (DB::table('ledger_accounts')->where('type', 'CLEARING')->where('balance', '!=', 0)->get() as $a) {
            if (! in_array((int) $a->project_id, $this->legacy, true)) {
                $e[] = 'Unexplained Clearing balance of '.$a->balance.' on account #'.$a->id.($a->project_id ? " (project #{$a->project_id})" : '').': Clearing is not a loss account.';
            }
        }
        foreach (DB::table('ledger_accounts')->where('type', 'CUSTODY_CASH')->where('balance', '<', 0)->pluck('id') as $id) {
            $e[] = "Custody cash account #$id is negative: client money was spent that the platform did not hold.";
        }
        // PlatformCash is the platform's own money: only Murabaha events may touch it (legacy types excepted).
        $allowed = array_merge(['MURABAHA_PURCHASE', 'MURABAHA_PAYMENT', 'MURABAHA_SALE', 'REVERSAL', 'ADJUSTMENT'], self::LEGACY_TYPES);
        foreach (DB::table('ledger_entries as en')->join('ledger_accounts as a', 'a.id', '=', 'en.ledger_account_id')->join('transactions as t', 't.id', '=', 'en.transaction_id')
            ->where('a.type', 'PLATFORM_CASH')->whereNotIn('t.type', $allowed)->select('t.id', 't.type')->distinct()->get() as $row) {
            $e[] = "Transaction #{$row->id} ({$row->type}) moved platform own funds, which only Murabaha events may do.";
        }
        foreach (DB::table('ledger_entries as en')->join('ledger_accounts as a', 'a.id', '=', 'en.ledger_account_id')->join('transactions as t', 't.id', '=', 'en.transaction_id')
            ->where('a.type', 'CAPITAL_DEPLOYED')->whereNotIn('t.type', self::LEGACY_TYPES)->select('t.id')->distinct()->pluck('t.id') as $id) {
            $e[] = "Transaction #$id posted to the retired CapitalDeployed account.";
        }
        if (! $this->legacy) {
            // Custody identity of the closed client-money system (holds by double entry; a break means a flow bypassed it).
            $sum = fn (array $types) => (int) DB::table('ledger_accounts')->whereIn('type', $types)->sum('balance');
            $lhs = $sum(['CUSTODY_CASH', 'VENTURE_CAPITAL']);
            $rhs = $sum(['INVESTOR_AVAILABLE', 'INVESTOR_PENDING', 'INVESTOR_INVESTED', 'BUSINESS_CAPITAL', 'PROJECT_FUNDS', 'BUSINESS_FUNDS']);
            if ($lhs !== $rhs) {
                $e[] = "Custody identity broken: custody cash + venture capital ($lhs) differs from participant claims ($rhs).";
            }
        }

        // Per project ----------------------------------------------------------------------------------------------
        foreach (DB::table('projects')->where('contract_type', '!=', 'MURABAHA')->get() as $p) {
            if (in_array((int) $p->id, $this->legacy, true)) {
                continue;
            }
            $tag = "Project #{$p->id}";
            $contract = DB::table('contracts')->where('project_id', $p->id)->first();
            $investments = DB::table('investments')->where('project_id', $p->id)->whereIn('status', self::LIVE)->get();
            $live = (int) $investments->sum('amount');
            $committed = DB::table('transactions')->where('type', 'INVESTMENT')->where('status', '!=', 'REVERSED')->whereIn('investment_id', $investments->pluck('id'))->get()->groupBy('investment_id');

            foreach ($investments as $i) {
                $t = $committed[$i->id] ?? collect();
                if ($t->count() !== 1 || (int) $t->first()->amount !== (int) $i->amount) {
                    $e[] = "$tag investment #{$i->id} has no single matching investment posting; investor capital commitment is not in the ledger.";
                }
            }
            if ((int) $p->funded_amount !== $live) {
                $e[] = "$tag funded amount ({$p->funded_amount}) does not equal accepted investment capital ($live).";
            }
            if (! $contract) {
                continue;
            }

            // Deployment, remittances, loss ------------------------------------------------------------------------
            $dep = DB::table('capital_deployments')->where('contract_id', $contract->id)->first();
            $business = $contract->contract_type === 'MUSHARAKAH' ? (int) DB::table('musharakah_capital_contributions')->where('contract_id', $contract->id)->sum('amount') : 0;
            if ($dep) {
                $tx = DB::table('transactions')->where('id', $dep->transaction_id)->first();
                if (! $tx || $tx->type !== 'CAPITAL_DEPLOYMENT' || (int) $tx->amount !== (int) $dep->amount || (int) $tx->project_id !== (int) $p->id) {
                    $e[] = "$tag capital deployment #{$dep->id} does not match its ledger transaction.";
                }
                if (! in_array($contract->status, ['COMPLETED'], true) && (int) $dep->amount !== $live + $business) {
                    $e[] = "$tag deployed capital ({$dep->amount}) differs from committed capital (".($live + $business).') (deployment #'.$dep->id.').';
                }
                if ($contract->status === 'APPROVED') {
                    $e[] = "$tag capital was deployed before the contract became active (deployment #{$dep->id}).";
                }
            }
            $D = (int) ($dep->amount ?? 0);
            $rem = DB::table('venture_remittances')->where('contract_id', $contract->id)->get();
            foreach ($rem as $r) {
                $tx = DB::table('transactions')->where('id', $r->transaction_id)->first();
                $want = $r->component === 'CAPITAL_RETURN' ? 'VENTURE_CAPITAL_RETURN' : 'INTERIM_PROCEEDS';
                if (! $tx || $tx->type !== $want || (int) $tx->amount !== (int) $r->amount) {
                    $e[] = "$tag remittance #{$r->id} does not match its ledger transaction.";
                }
            }
            if ($rem->isNotEmpty() && ! $dep) {
                $e[] = "$tag has remittances but no capital deployment.";
            }
            $Rc = (int) $rem->where('component', 'CAPITAL_RETURN')->sum('amount');
            $Rp = (int) $rem->where('component', 'INTERIM_PROCEEDS')->sum('amount');
            $W = $this->sumTx('LOSS_RECOGNITION', $p->id);

            $completed = $contract->status === 'COMPLETED';
            $vc = $this->bal('VENTURE_CAPITAL', $p->id);
            if ($vc !== $D - $Rc - $W) {
                $e[] = "$tag venture capital balance ($vc) is not explained by deployment, returns and recognised loss (expected ".($D - $Rc - $W).').';
            }
            if ($completed && $vc !== 0) {
                $e[] = "$tag has an unexplained venture capital balance of $vc after settlement.";
            }

            $profitDistributed = $this->sumTx('PROFIT_DISTRIBUTION', $p->id);
            $recReceived = $this->sumTx('RECOVERY_RECEIPT', $p->id);
            $recDistributed = $this->sumTx('RECOVERY_DISTRIBUTION', $p->id);
            $pool = $this->bal('PROJECT_FUNDS', $p->id);
            $expectedPool = $Rp + $recReceived - $profitDistributed - $recDistributed;
            if ($pool < 0) {
                $e[] = "$tag ProjectFunds is negative ($pool): money was distributed that the project did not hold.";
            }
            if ($pool !== $expectedPool) {
                $e[] = "$tag ProjectFunds balance ($pool) is not explained by interim proceeds, recoveries and distributions (expected $expectedPool).";
            }
            if ($completed && $pool !== 0) {
                $e[] = "$tag has an unexplained ProjectFunds balance of $pool after settlement.";
            }

            if ($contract->contract_type === 'MUSHARAKAH') {
                $sid = DB::table('settlements')->where('contract_id', $contract->id)->where('status', 'POSTED')->pluck('id');
                $items = DB::table('settlement_items')->whereIn('settlement_id', $sid);
                $lossB = abs((int) (clone $items)->where('item_type', 'BUSINESS_CAPITAL_LOSS')->sum('amount'));
                $retB = (int) (clone $items)->where('item_type', 'BUSINESS_CAPITAL_RETURN')->sum('amount');
                $bc = $this->bal('BUSINESS_CAPITAL', $p->id);
                if ($bc !== $business - $lossB - $retB) {
                    $e[] = "$tag business capital balance ($bc) is not explained by the contribution, loss and returns (expected ".($business - $lossB - $retB).').';
                }
                if ($completed && $bc !== 0) {
                    $e[] = "$tag has an unexplained business capital balance of $bc after settlement.";
                }
            }

            // Completed investments leave no claim behind -----------------------------------------------------------
            if ($completed) {
                $sid = DB::table('settlements')->where('contract_id', $contract->id)->where('status', 'POSTED')->pluck('id');
                foreach ($investments as $i) {
                    $it = DB::table('settlement_items')->whereIn('settlement_id', $sid)->where('investment_id', $i->id);
                    $left = (int) $i->amount - (int) (clone $it)->where('item_type', 'PRINCIPAL')->sum('amount') - abs((int) (clone $it)->where('item_type', 'ADJUSTMENT')->sum('amount'));
                    if ($left !== 0) {
                        $e[] = "$tag investment #{$i->id} still has $left of capital at risk after settlement.";
                    }
                }
            }
        }

        return $this->capped($e);
    }
}
