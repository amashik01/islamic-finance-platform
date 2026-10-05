<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

class IdempotencyIntegrity extends Check
{
    private const TABLES = ['deposits', 'withdrawals', 'investments', 'payments', 'transactions', 'settlements'];

    public function name(): string
    {
        return 'Idempotency Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->selectRaw('idempotency_key, COUNT(*) n')->whereNotNull('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->get() as $r) {
                $e[] = "$table has a duplicate idempotency key.";
            }
        }

        return $e;
    }

    /** Rows with a key but no request fingerprint predate the guard: they cannot detect a mismatched replay. */
    protected function warnings(): array
    {
        $w = [];
        foreach (self::TABLES as $table) {
            $n = DB::table($table)->whereNotNull('idempotency_key')->whereNull('request_hash')->count();
            $n && $w[] = "$table: $n record(s) have an idempotency key but no request fingerprint.";
        }

        return $w;
    }
}
