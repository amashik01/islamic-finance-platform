<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

class InvestmentIntegrity extends Check
{
    private const LIVE = ['CONFIRMED', 'ACTIVE', 'COMPLETED'];

    public function name(): string
    {
        return 'Investment Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        $tx = DB::table('transactions')->where('type', 'INVESTMENT')->whereNotNull('investment_id')->where('status', '!=', 'REVERSED')->get()->groupBy('investment_id');
        $investments = DB::table('investments as i')->join('investors as v', 'v.id', '=', 'i.investor_id')->select('i.*', 'v.user_id as investor_user_id')->get();

        foreach ($investments as $i) {
            if (! in_array($i->status, self::LIVE, true)) {
                continue;
            }
            $rows = $tx[$i->id] ?? collect();
            if ($rows->isEmpty()) {
                $e[] = "Investment #{$i->id} has no corresponding financial transaction.";
            } elseif ((int) $rows->sum('amount') !== (int) $i->amount || $rows->first()->user_id !== $i->investor_user_id) {
                $e[] = "Investment #{$i->id} does not match its financial transaction.";
            }
            $contract = $i->contract_id ? DB::table('contracts')->find($i->contract_id) : null;
            if ($contract && (int) $contract->project_id !== (int) $i->project_id) {
                $e[] = "Investment #{$i->id} references a contract of a different project.";
            }
            $project = DB::table('projects')->find($i->project_id);
            if ($project && $project->contract_type === 'MURABAHA') {
                $e[] = "Investment #{$i->id} was made in a Murabaha project (not an investment product).";
            }
        }
        $known = $investments->pluck('id')->all();
        foreach ($tx as $investmentId => $rows) {
            if (! in_array($investmentId, $known, true)) {
                $e[] = "Financial transaction #{$rows->first()->id} references missing investment #$investmentId.";
            }
        }

        // Project funding totals equal live investments.
        $sums = DB::table('investments')->whereIn('status', self::LIVE)->selectRaw('project_id, SUM(amount) s')->groupBy('project_id')->pluck('s', 'project_id');
        foreach (DB::table('projects')->select('id', 'funded_amount', 'contract_type')->get() as $p) {
            if ($p->contract_type !== 'MURABAHA' && (int) $p->funded_amount !== (int) ($sums[$p->id] ?? 0)) {
                $e[] = "Project #{$p->id} funded amount does not equal its investments.";
            }
        }

        return $this->capped($e);
    }
}
