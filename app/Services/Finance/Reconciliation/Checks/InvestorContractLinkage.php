<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** Every investment is backed by exactly one executed participation agreement of the same investor, project and amount. */
class InvestorContractLinkage extends Check
{
    private array $warnings = [];

    public function name(): string
    {
        return 'Investor Contract Linkage';
    }

    protected function errors(): array
    {
        $e = [];
        $rows = DB::table('investments as i')->join('investors as v', 'v.id', '=', 'i.investor_id')->join('contracts as c', 'c.id', '=', 'i.contract_id')
            ->whereIn('i.status', ['CONFIRMED', 'ACTIVE', 'COMPLETED'])->select('i.id', 'i.amount', 'i.project_id', 'i.participation_document_id', 'v.user_id', 'c.aqd_form_version')->get();
        foreach ($rows as $i) {
            $tag = "Investment #{$i->id}";
            if ($i->participation_document_id === null) {
                $i->aqd_form_version === null ? $this->warnings[] = "$tag is LEGACY: it predates participation agreements." : $e[] = "$tag has no participation agreement.";

                continue;
            }
            $d = DB::table('contract_documents')->find($i->participation_document_id);
            if (! $d || $d->kind !== 'PARTICIPATION') {
                $e[] = "$tag references a missing or non-participation agreement.";

                continue;
            }
            if ($d->status !== 'EXECUTED') {
                $e[] = "$tag is backed by agreement #{$d->id}, which is {$d->status}.";
            }
            if ((int) $d->party_user_id !== (int) $i->user_id) {
                $e[] = "$tag is backed by an agreement signed by a different investor (#{$d->id}).";
            }
            if ((int) $d->amount !== (int) $i->amount || (int) $d->project_id !== (int) $i->project_id) {
                $e[] = "$tag differs from agreement #{$d->id} in amount or project.";
            }
            if ((int) $d->consumed_by_investment_id !== (int) $i->id) {
                $e[] = "$tag: agreement #{$d->id} is not recorded as consumed by this investment.";
            }
        }
        foreach (DB::table('contract_documents')->where('kind', 'PARTICIPATION')->whereNotNull('consumed_by_investment_id')->whereNotExists(fn ($q) => $q->from('investments')->whereColumn('investments.id', 'contract_documents.consumed_by_investment_id'))->pluck('id') as $id) {
            $e[] = "Participation agreement #$id is consumed by an investment that does not exist.";
        }

        return $this->capped($e);
    }

    protected function warnings(): array
    {
        return $this->capped($this->warnings);
    }
}
