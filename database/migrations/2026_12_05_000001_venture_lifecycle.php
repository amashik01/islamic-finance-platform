<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive. Capital deployment and business remittance become explicit recorded events, and the manager-recovery
     * record gains an evidence-based lifecycle. No historical ledger row is rewritten.
     */
    public function up(): void
    {
        Schema::create('capital_deployments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->unique()->constrained()->cascadeOnDelete();   // one deployment per contract
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('reference')->unique();
            $t->string('delivery_reference', 150);        // bank/transfer reference of the actual delivery to the business
            $t->date('deployed_on');
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100)->unique();
            $t->string('request_hash', 64)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('venture_remittances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('component', 20);                  // CAPITAL_RETURN | INTERIM_PROCEEDS
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('reference')->unique();
            $t->string('receipt_reference', 150)->nullable();
            $t->date('received_on');
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100)->unique();
            $t->string('request_hash', 64)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['contract_id', 'component']);
        });

        Schema::table('manager_recoveries', function (Blueprint $t) {
            $t->unsignedBigInteger('claimed_amount')->nullable()->after('amount');
            $t->text('fault_evidence')->nullable();
            $t->foreignId('fault_established_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('fault_established_at')->nullable();
            $t->foreignId('liability_recognized_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('liability_recognized_at')->nullable();
            $t->unsignedBigInteger('distributed_amount')->default(0);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['capital_deployments', 'venture_remittances'] as $tbl) {
                DB::statement("ALTER TABLE `$tbl` ADD CONSTRAINT `chk_{$tbl}_bdt_only` CHECK (currency = 'BDT')");
                DB::statement("ALTER TABLE `$tbl` ADD CONSTRAINT `chk_{$tbl}_amount_positive` CHECK (amount > 0)");
            }
            DB::statement("ALTER TABLE `venture_remittances` ADD CONSTRAINT `chk_venture_remittances_component` CHECK (component IN ('CAPITAL_RETURN','INTERIM_PROCEEDS'))");
            DB::statement('ALTER TABLE `manager_recoveries` ADD CONSTRAINT `chk_recoveries_distributed_within_recovered` CHECK (distributed_amount <= recovered_amount)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `manager_recoveries` DROP CHECK `chk_recoveries_distributed_within_recovered`');
        }
        Schema::table('manager_recoveries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fault_established_by');
            $t->dropConstrainedForeignId('liability_recognized_by');
            $t->dropColumn(['claimed_amount', 'fault_evidence', 'fault_established_at', 'liability_recognized_at', 'distributed_amount']);
        });
        Schema::dropIfExists('venture_remittances');
        Schema::dropIfExists('capital_deployments');
    }
};
