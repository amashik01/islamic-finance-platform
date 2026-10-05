<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

class SettlementIntegrity extends Check
{
    public function name(): string
    {
        return 'Settlement Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        foreach (DB::table('settlements')->selectRaw('contract_id, COUNT(*) n')->where('status', 'POSTED')->groupBy('contract_id')->havingRaw('COUNT(*) > 1')->get() as $r) {
            $e[] = "Contract #{$r->contract_id} has {$r->n} posted settlements (duplicate settlement).";
        }

        foreach (DB::table('settlements')->where('status', 'POSTED')->get() as $s) {
            $contract = DB::table('contracts')->find($s->contract_id);
            $items = DB::table('settlement_items')->where('settlement_id', $s->id)->get();
            if ($items->isEmpty()) {
                $e[] = "Settlement #{$s->id} has no items.";
                continue;
            }
            if ($contract->status !== 'COMPLETED') {
                $e[] = "Settlement #{$s->id} is posted but its contract is not completed.";
            }
            if ($contract->contract_type === 'MURABAHA') {
                continue;   // Murabaha settlements are checked in Murabaha Receivables.
            }

            // Items that moved money must be backed by a ledger entry of the same size.
            foreach ($items as $i) {
                if (in_array($i->item_type, ['MANAGER_LIABILITY'], true)) {
                    continue;
                }
                $tx = $i->transaction_id ? DB::table('transactions')->find($i->transaction_id) : null;
                if (! $tx || (int) $tx->amount !== abs((int) $i->amount)) {
                    $e[] = "Settlement item #{$i->id} (settlement #{$s->id}) has no matching financial entry.";
                }
            }

            // Principal mismatch: per investment, principal returned + loss = capital invested.
            foreach (DB::table('investments')->where('project_id', $s->project_id)->whereIn('status', ['COMPLETED'])->get() as $inv) {
                $p = (int) $items->where('investment_id', $inv->id)->where('item_type', 'PRINCIPAL')->sum('amount');
                $loss = abs((int) $items->where('investment_id', $inv->id)->where('item_type', 'ADJUSTMENT')->sum('amount'));
                if ($p + $loss !== (int) $inv->amount) {
                    $e[] = "Settlement #{$s->id}: principal mismatch for investment #{$inv->id}.";
                }
            }

            $net = (int) $s->actual_net_result;
            // Musharakah: both partners' capital is accounted for — returned or lost, exactly the capital contributed.
            if ($contract->contract_type === 'MUSHARAKAH') {
                $inv = (int) $items->where('item_type', 'PRINCIPAL')->sum('amount') + abs((int) $items->where('item_type', 'ADJUSTMENT')->sum('amount'));
                $biz = (int) $items->where('item_type', 'BUSINESS_CAPITAL_RETURN')->sum('amount') + abs((int) $items->where('item_type', 'BUSINESS_CAPITAL_LOSS')->sum('amount'));
                $terms = DB::table('musharakah_contracts')->where('contract_id', $contract->id)->first();
                if ($terms && ($inv !== (int) $terms->investor_contribution || $biz !== (int) $terms->business_contribution)) {
                    $e[] = "Settlement #{$s->id} (contract #{$contract->id}, project #{$s->project_id}): Musharakah capital completeness failed — settled investor capital $inv / business capital $biz do not match the agreed contributions.";
                }
                if ($net < 0 && $terms && ($inv - (int) $items->where('item_type', 'PRINCIPAL')->sum('amount')) + abs((int) $items->where('item_type', 'BUSINESS_CAPITAL_LOSS')->sum('amount')) !== abs($net)) {
                    $e[] = "Settlement #{$s->id}: investor loss plus business loss does not equal the actual loss.";
                }
            }

            // Profit mismatch: investor profit + business profit = actual profit; none on a loss.
            $investorProfit = (int) $items->where('item_type', 'INVESTMENT_PROFIT')->sum('amount');
            $businessProfit = (int) $items->where('item_type', 'BUSINESS_PROFIT_SHARE')->sum('amount');
            $net = (int) $s->actual_net_result;
            if ($net > 0 ? ($investorProfit + $businessProfit) !== $net : ($investorProfit + $businessProfit) !== 0) {
                $e[] = "Settlement #{$s->id}: profit mismatch against the recorded actual result.";
            }

            // Manager liability is a recorded, recoverable amount (never just a status).
            $liability = (int) $items->where('item_type', 'MANAGER_LIABILITY')->sum('amount');
            $recovery = DB::table('manager_recoveries')->where('settlement_id', $s->id)->first();
            if ($liability > 0 && (! $recovery || (int) $recovery->amount !== $liability)) {
                $e[] = "Settlement #{$s->id}: manager liability is not recorded as a recoverable amount.";
            }
            if ($liability === 0 && $recovery) {
                $e[] = "Settlement #{$s->id}: a recovery exists without a manager liability item.";
            }
            if ($contract->recovery_status === 'IN_RECOVERY' && ! $recovery) {
                $e[] = "Contract #{$contract->id} is marked in recovery with no recoverable amount.";
            }
        }

        return $this->capped($e);
    }
}
