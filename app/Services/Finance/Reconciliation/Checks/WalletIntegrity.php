<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

class WalletIntegrity extends Check
{
    public function name(): string
    {
        return 'Wallet Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        foreach (DB::table('wallets')->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', '!=', 'BDT'))->pluck('id') as $id) {
            $e[] = "Wallet #$id has a non-BDT currency.";
        }

        // Wallet balances are derived from the ledger: cached balance must equal entries.
        $wallets = DB::table('ledger_accounts')->whereNotNull('wallet_id')->pluck('wallet_id', 'id');
        foreach (LedgerBalance::derivedBalances(true) as $row) {
            if ($row['cached'] !== $row['derived']) {
                $e[] = "Wallet #{$wallets[$row['id']]} does not match its ledger-derived balance (account #{$row['id']}).";
            }
            if ($row['derived'] < 0 || $row['cached'] < 0) {
                $e[] = "Wallet #{$wallets[$row['id']]} has a negative balance (account #{$row['id']}).";
            }
        }

        // Every wallet has exactly its three bucket accounts.
        foreach (DB::table('wallets')->pluck('id') as $id) {
            $types = DB::table('ledger_accounts')->where('wallet_id', $id)->pluck('type')->sort()->values()->all();
            if ($types !== ['INVESTOR_AVAILABLE', 'INVESTOR_INVESTED', 'INVESTOR_PENDING']) {
                $e[] = "Wallet #$id does not have exactly its available, invested and pending accounts.";
            }
        }

        // Pending bucket must equal the funds held for withdrawals still in flight.
        foreach (DB::table('wallets')->get() as $w) {
            $pending = (int) DB::table('ledger_accounts')->where('wallet_id', $w->id)->where('type', 'INVESTOR_PENDING')->value('balance');
            $held = (int) DB::table('withdrawals')->where('user_id', $w->user_id)->whereIn('status', ['PENDING', 'UNDER_REVIEW', 'APPROVED', 'PROCESSING'])->sum('amount');
            if ($pending !== $held) {
                $e[] = "Wallet #{$w->id} pending balance does not equal its open withdrawals.";
            }
        }

        return $this->capped($e);
    }
}
