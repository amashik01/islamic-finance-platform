<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Models\ContractDocument;
use App\Services\Aqd\ContractSigningService;
use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** Agreement lifecycle, hash and signature integrity: nothing executed without its signatures, nothing altered after generation. */
class AqdDocumentIntegrity extends Check
{
    public function name(): string
    {
        return 'Aqd Document Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        $signing = app(ContractSigningService::class);
        foreach (ContractDocument::with('signatures')->orderBy('id')->cursor() as $d) {
            $tag = "Agreement #{$d->id} ({$d->kind->value}, project #{$d->project_id})";
            if (! $d->hashIntact()) {
                $e[] = "$tag: the stored text no longer matches its recorded document hash.";
            }
            foreach ($d->signatures as $s) {
                if ($s->document_hash !== $d->document_hash) {
                    $e[] = "$tag: signature #{$s->id} is bound to a different document hash.";
                }
            }
            if ($d->status->value === 'EXECUTED' || $d->status->value === 'SUPERSEDED') {
                $signed = $d->signatures->map(fn ($s) => $s->signer_role->value)->all();
                foreach ($signing->requiredSigners($d) as $req) {
                    if (! in_array($req['role']->value, $signed, true)) {
                        $e[] = "$tag is {$d->status->value} but the {$req['role']->value} signature is missing.";
                    }
                }
                if ($d->executed_at === null) {
                    $e[] = "$tag is {$d->status->value} without an execution time.";
                }
            }
            if (in_array($d->status->value, ['DRAFT', 'PENDING_SIGNATURE', 'CANCELLED'], true) && $d->signatures->isNotEmpty() && $d->status->value !== 'PENDING_SIGNATURE') {
                $e[] = "$tag has signatures although it is {$d->status->value}.";
            }
            if ($d->status->value === 'PENDING_SIGNATURE' && $d->kind->value === 'MASTER_AQD') {
                $r = $d->shariah_review_id ? DB::table('shariah_reviews')->find($d->shariah_review_id) : null;
                if (! $r || $r->status !== 'APPROVED') {
                    $e[] = "$tag is open for signature without an approved Shariah review.";
                }
            }
        }
        // At most one live executed master per contract; a superseded one must have an executed successor.
        foreach (DB::table('contract_documents')->where('kind', 'MASTER_AQD')->where('status', 'EXECUTED')->select('contract_id', DB::raw('count(*) as n'))->groupBy('contract_id')->having('n', '>', 1)->pluck('contract_id') as $cid) {
            $e[] = "Contract #$cid has more than one executed master agreement; the older one must be superseded.";
        }
        foreach (DB::table('contract_documents as o')->where('o.status', 'SUPERSEDED')->whereNotExists(fn ($q) => $q->from('contract_documents as n')->whereColumn('n.supersedes_id', 'o.id')->where('n.status', 'EXECUTED'))->pluck('o.id') as $id) {
            $e[] = "Agreement #$id is superseded but no executed agreement replaced it.";
        }

        return $this->capped($e);
    }
}
