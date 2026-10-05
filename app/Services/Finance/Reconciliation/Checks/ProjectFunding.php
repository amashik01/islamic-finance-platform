<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/**
 * Project funding completeness. Every live investment must have exactly one funding leg
 * (Dr CapitalDeployed / Cr ProjectFunds) of the same size, and the project pool must hold exactly what is explained by
 * unsettled capital + remitted proceeds — never more, never less, never negative.
 */
class ProjectFunding extends Check
{
    private const LIVE = ['CONFIRMED', 'ACTIVE', 'COMPLETED'];

    public function name(): string
    {
        return 'Project Funding';
    }

    private function accountBalance(string $type, int $projectId): int
    {
        return (int) DB::table('ledger_accounts')->where('type', $type)->where('project_id', $projectId)->value('balance');
    }

    protected function errors(): array
    {
        $e = [];
        $funding = DB::table('transactions')->where('type', 'PROJECT_FUNDING')->where('status', '!=', 'REVERSED')->get()->groupBy('investment_id');
        $release = DB::table('transactions')->where('type', 'CAPITAL_RELEASE')->where('status', '!=', 'REVERSED')->get()->groupBy('investment_id');
        $known = DB::table('investments')->pluck('id')->all();

        foreach ($funding as $investmentId => $rows) {
            if (! in_array($investmentId, $known, true)) {
                $e[] = "Funding transaction #{$rows->first()->id} references a missing investment #$investmentId.";
            }
        }

        foreach (DB::table('projects')->where('contract_type', '!=', 'MURABAHA')->get() as $p) {
            $investments = DB::table('investments')->where('project_id', $p->id)->whereIn('status', self::LIVE)->get();
            $tag = "Project #{$p->id}";

            foreach ($investments as $i) {
                $f = $funding[$i->id] ?? collect();
                if ($f->count() !== 1) {
                    $e[] = "$tag investment #{$i->id} has {$f->count()} funding movements (expected exactly one); investor capital is not in the project pool.";
                } elseif ((int) $f->first()->amount !== (int) $i->amount || (int) $f->first()->project_id !== (int) $p->id) {
                    $e[] = "$tag investment #{$i->id} funding transaction #{$f->first()->id} does not match the investment.";
                }
                $r = $release[$i->id] ?? collect();
                if ($i->status === 'COMPLETED' && ($r->count() !== 1 || (int) $r->first()->amount !== (int) $i->amount)) {
                    $e[] = "$tag investment #{$i->id} is settled but its capital release is missing or wrong.";
                }
                if ($i->status !== 'COMPLETED' && $r->isNotEmpty()) {
                    $e[] = "$tag investment #{$i->id} capital was released before settlement (transaction #{$r->first()->id}).";
                }
            }

            $live = (int) $investments->sum('amount');
            $fundedLedger = (int) $investments->sum(fn ($i) => (int) (($funding[$i->id] ?? collect())->sum('amount')));
            if ($fundedLedger !== $live) {
                $e[] = "$tag funding completeness: investments total $live but the pool was funded $fundedLedger.";
            }
            if ((int) $p->funded_amount !== $live) {
                $e[] = "$tag funded amount ({$p->funded_amount}) does not equal accepted investment capital ($live).";
            }

            // Pool and offset balances.
            $unsettled = (int) $investments->whereIn('status', ['CONFIRMED', 'ACTIVE'])->sum('amount');
            $deployed = $this->accountBalance('CAPITAL_DEPLOYED', $p->id);
            $pool = $this->accountBalance('PROJECT_FUNDS', $p->id);
            if ($deployed !== $unsettled) {
                $e[] = "$tag capital deployed balance ($deployed) does not equal unsettled investor capital ($unsettled).";
            }
            if ($pool < 0) {
                $e[] = "$tag ProjectFunds is negative ($pool): money was distributed that the project did not hold.";
            }

            $contract = DB::table('contracts')->where('project_id', $p->id)->first();
            $bizUnsettled = $contract ? (int) DB::table('musharakah_capital_contributions')->where('contract_id', $contract->id)->where('status', 'RECEIVED')->sum('amount') : 0;
            $remitted = (int) DB::table('transactions')->where('type', 'BUSINESS_REMITTANCE')->where('project_id', $p->id)->where('status', '!=', 'REVERSED')->sum('amount');
            $completed = $contract && $contract->status === 'COMPLETED';
            $expected = $completed ? 0 : $unsettled + $bizUnsettled + $remitted;
            if ($completed && $pool !== 0) {
                $e[] = "$tag has an unexplained ProjectFunds balance of $pool after settlement.";
            } elseif (! $completed && $contract && $pool !== $expected) {
                $e[] = "$tag ProjectFunds balance ($pool) is not explained by capital and remittances (expected $expected).";
            }
        }

        return $this->capped($e);
    }
}
