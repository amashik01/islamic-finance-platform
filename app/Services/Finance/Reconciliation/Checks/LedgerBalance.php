<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

class LedgerBalance extends Check
{
    public function name(): string
    {
        return 'Ledger Balance';
    }

    protected function errors(): array
    {
        $e = [];

        // Every transaction balances: SUM(debits) = SUM(credits) and equals its gross amount.
        $sums = DB::table('ledger_entries')->selectRaw("transaction_id, SUM(CASE WHEN direction = 'DEBIT' THEN amount ELSE 0 END) d, SUM(CASE WHEN direction = 'CREDIT' THEN amount ELSE 0 END) c, COUNT(*) n")->groupBy('transaction_id')->get()->keyBy('transaction_id');
        foreach (DB::table('transactions')->select('id', 'amount')->get() as $t) {
            $s = $sums[$t->id] ?? null;
            if (! $s || $s->n < 2) {
                $e[] = "Ledger transaction #{$t->id} has fewer than two entries.";
            } elseif ((int) $s->d !== (int) $s->c) {
                $e[] = "Ledger transaction #{$t->id} is unbalanced (debits {$s->d} vs credits {$s->c}).";
            } elseif ((int) $s->d !== (int) $t->amount) {
                $e[] = "Ledger transaction #{$t->id} gross amount does not match its entries.";
            }
        }

        // Invalid references.
        foreach (DB::table('ledger_entries as e')->leftJoin('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')->whereNull('a.id')->pluck('e.id') as $id) {
            $e[] = "Ledger entry #$id references a missing account.";
        }
        foreach (DB::table('ledger_entries as e')->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')->whereNull('t.id')->pluck('e.id') as $id) {
            $e[] = "Ledger entry #$id references a missing transaction.";
        }

        // Cached balances equal the balance derived from entries (system accounts here; wallet accounts in Wallet Integrity).
        foreach ($this->derived(null) as $row) {
            if ($row['cached'] !== $row['derived']) {
                $e[] = "Ledger account #{$row['id']} cached balance does not match its entries.";
            }
        }

        // Duplicates: one INVESTMENT posting per investment; duplicate idempotency keys.
        foreach (DB::table('transactions')->selectRaw('investment_id, COUNT(*) n')->where('type', 'INVESTMENT')->where('status', '!=', 'REVERSED')->whereNotNull('investment_id')->groupBy('investment_id')->havingRaw('COUNT(*) > 1')->get() as $r) {
            $e[] = "Investment #{$r->investment_id} has {$r->n} investment postings (duplicate financial transaction).";
        }
        foreach (DB::table('transactions')->selectRaw('idempotency_key, COUNT(*) n')->whereNotNull('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->get() as $r) {
            $e[] = 'Duplicate ledger idempotency key detected.';
        }

        // Reversals are explicit and paired; history is never mutated.
        foreach (DB::table('transactions as t')->where('t.status', 'REVERSED')->whereNotExists(fn ($q) => $q->from('transactions as r')->whereColumn('r.reverses_transaction_id', 't.id'))->pluck('t.id') as $id) {
            $e[] = "Ledger transaction #$id is marked reversed but has no reversal entry.";
        }
        foreach (DB::table('transactions as r')->join('transactions as o', 'o.id', '=', 'r.reverses_transaction_id')->where('o.status', '!=', 'REVERSED')->pluck('r.id') as $id) {
            $e[] = "Reversal #$id points at a transaction that is not marked reversed.";
        }

        return $this->capped($e);
    }

    /**
     * @return list<array{id:int, cached:int, derived:int}>
     */
    public static function derivedBalances(?bool $walletAccounts): array
    {
        $agg = DB::table('ledger_entries')->selectRaw("ledger_account_id, SUM(CASE WHEN direction = 'DEBIT' THEN amount ELSE 0 END) d, SUM(CASE WHEN direction = 'CREDIT' THEN amount ELSE 0 END) c")->groupBy('ledger_account_id')->get()->keyBy('ledger_account_id');
        $q = DB::table('ledger_accounts')->select('id', 'normal_side', 'balance');
        if ($walletAccounts !== null) {
            $walletAccounts ? $q->whereNotNull('wallet_id') : $q->whereNull('wallet_id');
        }
        $out = [];
        foreach ($q->get() as $a) {
            $d = (int) ($agg[$a->id]->d ?? 0);
            $c = (int) ($agg[$a->id]->c ?? 0);
            $out[] = ['id' => (int) $a->id, 'cached' => (int) $a->balance, 'derived' => $a->normal_side === 'DEBIT' ? $d - $c : $c - $d];
        }

        return $out;
    }

    private function derived(?bool $wallet): array
    {
        return self::derivedBalances($wallet === null ? false : $wallet);
    }
}
