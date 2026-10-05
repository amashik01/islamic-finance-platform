<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** The financial terms printed in the executed agreement are the terms the books use; they cannot drift apart silently. */
class AqdTermIntegrity extends Check
{
    public function name(): string
    {
        return 'Aqd Term Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        $docs = DB::table('contract_documents as d')->join('contracts as c', 'c.id', '=', 'd.contract_id')->where('d.kind', 'MASTER_AQD')->where('d.status', 'EXECUTED')->select('d.id', 'd.contract_id', 'd.terms_snapshot', 'c.contract_type')->get();
        foreach ($docs as $d) {
            // An approved amendment awaiting signature has already moved the stored terms; compare once it is executed.
            if (DB::table('contract_amendments as a')->join('contract_documents as f', 'f.id', '=', 'a.from_document_id')->where('f.id', $d->id)->where('a.status', 'APPROVED')->exists()) {
                continue;
            }
            $snap = json_decode($d->terms_snapshot, true) ?: [];
            $tag = "Agreement #{$d->id} (contract #{$d->contract_id})";
            $expect = [];
            if ($d->contract_type === 'MUDARABAH' && ($t = DB::table('mudarabah_contracts')->where('contract_id', $d->contract_id)->first())) {
                $expect = ['capital_required' => (int) $t->capital_required, 'rabb_ratio' => (int) $t->investor_profit_bps, 'mudarib_ratio' => (int) $t->business_profit_bps];
            } elseif ($d->contract_type === 'MURABAHA' && ($t = DB::table('murabaha_contracts')->where('contract_id', $d->contract_id)->first())) {
                $expect = ['acquisition_cost' => (int) $t->purchase_cost, 'sale_price' => (int) $t->sale_price];
            }
            foreach ($expect as $key => $value) {
                // Money and percentages are both compared at two decimals (minor units / basis points).
                if (isset($snap[$key]) && (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) $snap[$key])) * 100) !== $value) {
                    $e[] = "$tag: '$key' reads {$snap[$key]} in the agreement but the contract terms hold $value (minor units / basis points).";
                }
            }
        }

        return $this->capped($e);
    }
}
