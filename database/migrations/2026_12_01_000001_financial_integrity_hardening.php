<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that carry a currency column. BDT is the only value allowed. */
    private const CURRENCY_TABLES = ['projects', 'contracts', 'investments', 'wallets', 'ledger_accounts', 'transactions', 'deposits', 'withdrawals', 'settlements'];

    public function up(): void
    {
        // Request fingerprints: same key + same request => original result; same key + different request => conflict.
        foreach (['deposits', 'withdrawals', 'investments', 'payments', 'transactions', 'settlements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('request_hash', 64)->nullable());
        }

        Schema::table('settlements', function (Blueprint $t) {
            $t->string('idempotency_key', 100)->nullable()->unique();
            $t->unique('contract_id');   // a contract is settled exactly once
        });

        // Musharakah: loss follows capital ratio. An "agreed ratio" exception needs Shariah approval + reason.
        Schema::table('musharakah_contracts', function (Blueprint $t) {
            $t->text('loss_exception_reason')->nullable();
            $t->foreignId('loss_exception_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('loss_exception_approved_at')->nullable();
        });

        // Minimum auditable representation of an amount recoverable from a manager at fault (Mudarabah).
        Schema::create('manager_recoveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('settlement_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('recovered_amount')->default(0);
            $t->string('currency', 3)->default('BDT');
            $t->string('status', 20)->default('OPEN')->index();
            $t->text('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        $this->databaseChecks();
    }

    /** Real CHECK constraints (MySQL 8.0.16+). SQLite cannot add them after creation; model guards + reconciliation cover it there. */
    private function databaseChecks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $checks = [];
        foreach ([...self::CURRENCY_TABLES, 'manager_recoveries'] as $t) {
            $checks[] = [$t, "chk_{$t}_bdt_only", "currency = 'BDT'"];
        }
        $checks = array_merge($checks, [
            ['transactions', 'chk_transactions_amount_positive', 'amount > 0'],
            ['ledger_entries', 'chk_ledger_entries_amount_positive', 'amount > 0'],
            ['ledger_entries', 'chk_ledger_entries_direction', "direction in ('DEBIT','CREDIT')"],
            ['investments', 'chk_investments_amount_positive', 'amount > 0'],
            ['deposits', 'chk_deposits_amount_positive', 'amount > 0'],
            ['withdrawals', 'chk_withdrawals_amount_positive', 'amount > 0'],
            ['payments', 'chk_payments_amount_positive', 'amount > 0'],
            ['projects', 'chk_projects_funded_within_target', 'funded_amount <= funding_target'],
            ['receivables', 'chk_receivables_paid_within_total', 'paid_amount <= total_amount'],
            ['payment_schedules', 'chk_schedules_paid_within_amount', 'paid_amount <= amount'],
            ['manager_recoveries', 'chk_recoveries_recovered_within_amount', 'recovered_amount <= amount'],
        ]);
        foreach ($checks as [$table, $name, $expr]) {
            DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expr)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach ([...self::CURRENCY_TABLES, 'manager_recoveries'] as $t) {
                // manager_recoveries is dropped below
                if ($t !== 'manager_recoveries') {
                    DB::statement("ALTER TABLE `$t` DROP CHECK `chk_{$t}_bdt_only`");
                }
            }
        }
        Schema::dropIfExists('manager_recoveries');
        Schema::table('musharakah_contracts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('loss_exception_approved_by');
            $t->dropColumn(['loss_exception_reason', 'loss_exception_approved_at']);
        });
        // MySQL needs an index for the foreign key, so release the FK, drop the unique index, then restore the FK.
        Schema::table('settlements', function (Blueprint $t) {
            $t->dropForeign(['contract_id']);
            $t->dropUnique(['contract_id']);
            $t->dropUnique(['idempotency_key']);
            $t->dropColumn('idempotency_key');
        });
        Schema::table('settlements', fn (Blueprint $t) => $t->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete());
        foreach (['deposits', 'withdrawals', 'investments', 'payments', 'transactions', 'settlements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('request_hash'));
        }
    }
};
