<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** Contract activation and Musharakah capital completeness. */
class ContractLifecycleIntegrity extends Check
{
    public function name(): string
    {
        return 'Contract Lifecycle';
    }

    protected function errors(): array
    {
        $e = [];
        $contracts = DB::table('contracts as c')->join('projects as p', 'p.id', '=', 'c.project_id')
            ->whereIn('c.contract_type', ['MUDARABAH', 'MUSHARAKAH'])
            ->select('c.*', 'p.status as pstatus', 'p.funded_amount', 'p.funding_target')->get();

        foreach ($contracts as $c) {
            $tag = "Contract #{$c->id} (project #{$c->project_id})";
            $funded = $c->funding_target > 0 && $c->funded_amount >= $c->funding_target;

            if (in_array($c->pstatus, ['ACTIVE', 'COMPLETED'], true) && ! in_array($c->status, ['ACTIVE', 'COMPLETED', 'DEFAULTED'], true)) {
                $e[] = "$tag: the project is {$c->pstatus} but the contract is still {$c->status}.";
            }
            if (in_array($c->status, ['ACTIVE', 'COMPLETED'], true)) {
                if (! $funded) {
                    $e[] = "$tag is {$c->status} but the project is not fully funded.";
                }
                $review = DB::table('shariah_reviews')->where('project_id', $c->project_id)->orderByDesc('id')->first();
                if (! $review || $review->status !== 'APPROVED') {
                    $e[] = "$tag is {$c->status} without an approved Shariah review.";
                }
            }
            if ($c->status === 'ACTIVE' && $c->pstatus === 'FUNDING') {
                $e[] = "$tag is active while its project is still funding.";
            }
            if ($c->status === 'COMPLETED' && DB::table('settlements')->where('contract_id', $c->id)->where('status', 'POSTED')->count() !== 1) {
                $e[] = "$tag is completed without exactly one posted settlement.";
            }
            if (DB::table('audit_logs')->where('action', 'contract.activated')->where('auditable_type', 'App\\Models\\Contract')->where('auditable_id', $c->id)->count() > 1) {
                $e[] = "$tag was activated more than once.";
            }

            if ($c->contract_type === 'MUSHARAKAH') {
                $t = DB::table('musharakah_contracts')->where('contract_id', $c->id)->first();
                $m = DB::table('musharakah_capital_contributions')->where('contract_id', $c->id)->first();
                if ($t && (int) $t->investor_contribution + (int) $t->business_contribution !== (int) $t->total_capital) {
                    $e[] = "$tag: investor and business capital do not add up to the total Musharakah capital.";
                }
                if ($t && (int) $c->funding_target !== (int) $t->investor_contribution) {
                    $e[] = "$tag: the funding target differs from the agreed investor contribution.";
                }
                if (in_array($c->status, ['ACTIVE', 'COMPLETED'], true) && ! $m) {
                    $e[] = "$tag is {$c->status} but the business capital contribution was never recorded.";
                }
                if ($m) {
                    $tx = $m->transaction_id ? DB::table('transactions')->find($m->transaction_id) : null;
                    if (! $tx || $tx->type !== 'MUSHARAKAH_CAPITAL' || (int) $tx->amount !== (int) $m->amount || (int) $tx->project_id !== (int) $c->project_id) {
                        $e[] = "$tag: the business contribution #{$m->id} is not backed by a matching ledger transaction.";
                    }
                    if ($t && (int) $m->amount !== (int) $t->business_contribution) {
                        $e[] = "$tag: the recorded business contribution ({$m->amount}) differs from the agreed terms ({$t->business_contribution}).";
                    }
                    if ($c->status === 'COMPLETED' && $m->status !== 'SETTLED') {
                        $e[] = "$tag is completed but its business contribution is not marked settled.";
                    }
                }
            }
        }

        return $this->capped($e);
    }
}
