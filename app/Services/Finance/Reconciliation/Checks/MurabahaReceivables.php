<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** The Murabaha subledger (sale, receivable, schedule, payments) must reconcile with the general ledger. */
class MurabahaReceivables extends Check
{
    private const ORDER = ['REQUESTED', 'VERIFIED', 'PURCHASED', 'OWNED', 'POSSESSED', 'SOLD', 'SETTLED'];

    public function name(): string
    {
        return 'Murabaha Receivables';
    }

    private function balance(string $type, int $projectId): int
    {
        return (int) DB::table('ledger_accounts')->where('type', $type)->where('project_id', $projectId)->value('balance');
    }

    protected function errors(): array
    {
        $e = [];
        foreach (DB::table('murabaha_contracts as m')->join('contracts as c', 'c.id', '=', 'm.contract_id')->select('m.*', 'c.project_id', 'c.id as cid', 'c.status as cstatus')->get() as $m) {
            $at = array_search($m->stage, self::ORDER, true);
            $tag = "Murabaha contract #{$m->cid}";
            $purchase = DB::table('murabaha_purchases')->where('murabaha_contract_id', $m->id)->first();
            $sale = DB::table('murabaha_sales')->where('murabaha_contract_id', $m->id)->first();

            if ((int) $m->sale_price !== (int) $m->purchase_cost + (int) $m->sale_profit) {
                $e[] = "$tag: sale price is not purchase cost plus sale profit.";
            }
            // Lifecycle: later stages require the earlier facts.
            if ($at >= 2 && (! $purchase || (int) $purchase->amount !== (int) $m->purchase_cost)) {
                $e[] = "$tag: invalid lifecycle — purchase record missing or wrong amount.";
            }
            if ($at >= 3 && (! $purchase || ! $purchase->ownership_acquired_on)) {
                $e[] = "$tag: invalid lifecycle — ownership not recorded.";
            }
            if ($at >= 4 && (! $purchase || ! $purchase->possession_on)) {
                $e[] = "$tag: invalid lifecycle — possession (qabd) not recorded.";
            }
            if ($at >= 5 && ! $sale) {
                $e[] = "$tag: invalid lifecycle — sold without a sale record.";
            }
            if ($at < 2 && $purchase) {
                $e[] = "$tag: purchase recorded before the purchase stage.";
            }
            if ($at < 5 && $sale) {
                $e[] = "$tag: sale recorded before the sale stage.";
            }
            if ($at >= 2 && ! DB::table('transactions')->where('type', 'MURABAHA_PURCHASE')->where('project_id', $m->project_id)->exists()) {
                $e[] = "$tag: asset purchase has no ledger entry.";
            }
            if (! $sale) {
                continue;
            }

            $r = DB::table('receivables')->where('murabaha_sale_id', $sale->id)->first();
            if ((int) $sale->sale_profit !== (int) $m->sale_profit || (int) $sale->purchase_cost !== (int) $m->purchase_cost || (int) $sale->sale_price !== (int) $m->sale_price) {
                $e[] = "$tag: sale profit/price differs from the contract terms.";
            }
            if (! $r) {
                $e[] = "$tag: sale without a receivable.";
                continue;
            }
            $paid = (int) DB::table('payments')->where('receivable_id', $r->id)->sum('amount');
            $scheduled = (int) DB::table('payment_schedules')->where('receivable_id', $r->id)->sum('amount');
            $scheduledPaid = (int) DB::table('payment_schedules')->where('receivable_id', $r->id)->sum('paid_amount');

            if ((int) $r->total_amount !== (int) $sale->sale_price) {
                $e[] = "$tag: receivable mismatch — total differs from the sale price.";
            }
            if ($paid > (int) $sale->sale_price) {
                $e[] = "$tag: payments exceed the sale price.";
            }
            if ((int) $r->paid_amount !== $paid) {
                $e[] = "$tag: receivable paid amount does not equal the sum of payments.";
            }
            if ($scheduled !== (int) $r->total_amount || $scheduledPaid !== $paid) {
                $e[] = "$tag: payment schedule does not reconcile with the receivable.";
            }
            $outstanding = (int) $r->total_amount - $paid;
            if ($outstanding < 0) {
                $e[] = "$tag: outstanding amount is negative.";
            }
            // Receivable subledger = general ledger.
            if ($this->balance('MURABAHA_RECEIVABLE', $m->project_id) !== $outstanding) {
                $e[] = "$tag: outstanding amount does not match the ledger receivable account.";
            }
            if ($this->balance('MURABAHA_INVENTORY', $m->project_id) !== 0) {
                $e[] = "$tag: sold asset is still held in inventory.";
            }
            if ($this->balance('MURABAHA_SALE_PROFIT', $m->project_id) !== (int) $sale->sale_profit) {
                $e[] = "$tag: recognised sale profit does not match the sale.";
            }
            if (DB::table('payments')->where('receivable_id', $r->id)->whereNull('transaction_id')->exists()) {
                $e[] = "$tag: a payment has no ledger entry.";
            }
            if (($outstanding === 0) !== ($r->status === 'PAID')) {
                $e[] = "$tag: receivable status does not match its balance.";
            }
            if ($m->stage === 'SETTLED' && $outstanding !== 0) {
                $e[] = "$tag: settled with an outstanding balance.";
            }
            if ($outstanding === 0 && $m->stage !== 'SETTLED') {
                $e[] = "$tag: fully paid but not settled.";
            }
        }

        return $this->capped($e);
    }
}
