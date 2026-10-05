<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** BDT is the only currency: any other value anywhere is a reconciliation failure. */
class CurrencyIntegrity extends Check
{
    public const TABLES = ['projects', 'contracts', 'investments', 'wallets', 'ledger_accounts', 'transactions', 'deposits', 'withdrawals', 'settlements', 'manager_recoveries'];

    public function name(): string
    {
        return 'Currency Integrity';
    }

    protected function errors(): array
    {
        $errors = [];
        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', '!=', 'BDT'))->pluck('id') as $id) {
                $errors[] = "$table #$id has a non-BDT currency.";
            }
        }
        // Ledger entries inherit currency from their account.
        foreach (DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')->where('a.currency', '!=', 'BDT')->pluck('e.id') as $id) {
            $errors[] = "ledger_entries #$id references a non-BDT account.";
        }

        return $this->capped($errors);
    }
}
