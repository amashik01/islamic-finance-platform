<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the Musharakah business-capital contribution record. Additive only: no existing financial row is
     * rewritten. (Project funding legs and the CapitalDeployed account are created through LedgerService,
     * so no schema change is needed for them; historical ledger entries are never touched.)
     */
    public function up(): void
    {
        Schema::create('musharakah_capital_contributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->unique()->constrained()->cascadeOnDelete();   // one contribution per contract
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('contribution_type', 30)->default('BUSINESS_CAPITAL');
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('status', 20)->default('RECEIVED')->index();
            $t->string('reference')->unique();
            $t->date('received_on');
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100)->unique();
            $t->string('request_hash', 64)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `musharakah_capital_contributions` ADD CONSTRAINT `chk_musharakah_capital_bdt_only` CHECK (currency = 'BDT')");
            DB::statement('ALTER TABLE `musharakah_capital_contributions` ADD CONSTRAINT `chk_musharakah_capital_positive` CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('musharakah_capital_contributions');
    }
};
