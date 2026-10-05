<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** request -> promise -> purchase -> ownership -> qabd -> risk -> executed sale agreement -> sale -> receivable, in that order and with evidence. */
class MurabahaSequenceIntegrity extends Check
{
    private array $warnings = [];

    public function name(): string
    {
        return 'Murabaha Sequence';
    }

    protected function errors(): array
    {
        $e = [];
        foreach (DB::table('murabaha_contracts as m')->join('contracts as c', 'c.id', '=', 'm.contract_id')->select('m.id', 'm.contract_id', 'm.stage', 'c.aqd_form_version', 'c.aqd_terms')->get() as $m) {
            $tag = "Murabaha #{$m->id} (contract #{$m->contract_id})";
            $sale = DB::table('murabaha_sales')->where('murabaha_contract_id', $m->id)->first();
            $purchase = DB::table('murabaha_purchases')->where('murabaha_contract_id', $m->id)->first();
            $receivable = $sale ? DB::table('receivables')->where('murabaha_sale_id', $sale->id)->first() : null;
            $stages = ['REQUESTED', 'VERIFIED', 'PURCHASED', 'OWNED', 'POSSESSED', 'SOLD', 'SETTLED'];
            $at = array_search($m->stage, $stages, true);

            if ($sale && (! $purchase || ! $purchase->ownership_acquired_on || ! $purchase->possession_on)) {
                $e[] = "$tag was sold without recorded ownership and possession of the asset.";
            }
            if ($sale && $purchase) {
                if ($purchase->purchased_on && $purchase->ownership_acquired_on && $purchase->ownership_acquired_on < $purchase->purchased_on) {
                    $e[] = "$tag: ownership is dated before the purchase.";
                }
                if ($purchase->possession_on && $sale->sold_on && $sale->sold_on < $purchase->possession_on) {
                    $e[] = "$tag: the sale is dated before possession.";
                }
            }
            if ($receivable && ! $sale) {
                $e[] = "$tag has a receivable without a sale.";
            }
            if ($sale && ! $receivable) {
                $e[] = "$tag has a sale without its receivable.";
            }
            if ($sale && $receivable && (int) $receivable->total_amount !== (int) $sale->sale_price) {
                $e[] = "$tag: the receivable differs from the sale price.";
            }
            if ($at !== false && $at >= 5 && ! $sale) {
                $e[] = "$tag is at stage {$m->stage} without a sale record.";
            }
            if ($sale) {
                $legacy = $m->aqd_form_version === null;
                if (! $purchase?->risk_confirmed_on) {
                    $legacy ? $this->warnings[] = "$tag is LEGACY: no seller risk confirmation was recorded." : $e[] = "$tag was sold without the seller's risk confirmation.";
                }
                $doc = $sale->sale_document_id ? DB::table('contract_documents')->find($sale->sale_document_id) : null;
                if (! $doc || $doc->kind !== 'MURABAHA_SALE' || $doc->status !== 'EXECUTED') {
                    $legacy ? $this->warnings[] = "$tag is LEGACY: no executed sale agreement." : $e[] = "$tag was sold without an executed sale agreement.";
                } elseif ((int) $doc->contract_id !== (int) $m->contract_id) {
                    $e[] = "$tag: the sale agreement belongs to a different contract.";
                }
                $terms = json_decode((string) $m->aqd_terms, true) ?: [];
                if (! empty($terms['use_promise']) && ! DB::table('murabaha_promises')->where('murabaha_contract_id', $m->id)->exists()) {
                    $e[] = "$tag uses a promise but none was recorded.";
                }
            }
        }
        // A receivable balance in the ledger without any sale is the classic "loan disguised as a sale".
        foreach (DB::table('ledger_accounts')->where('type', 'MURABAHA_RECEIVABLE')->where('balance', '>', 0)->whereNotNull('project_id')->get() as $a) {
            $sold = DB::table('murabaha_sales as s')->join('murabaha_contracts as m', 'm.id', '=', 's.murabaha_contract_id')->join('contracts as c', 'c.id', '=', 'm.contract_id')->where('c.project_id', $a->project_id)->exists();
            $sold || $e[] = "Ledger account #{$a->id} holds a Murabaha receivable for project #{$a->project_id}, which has no sale.";
        }

        return $this->capped($e);
    }

    protected function warnings(): array
    {
        return $this->capped($this->warnings);
    }
}
