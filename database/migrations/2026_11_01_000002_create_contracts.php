<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->string('contract_number')->unique();
            $t->string('contract_type', 20)->index();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('DRAFT')->index();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable()->index();
            $t->string('currency', 3)->default('BDT');
            $t->string('recovery_status', 20)->default('NONE');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        // Ratios are basis points (10000 = 100%).
        Schema::create('mudarabah_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('capital_required');
            $t->unsignedBigInteger('business_contribution')->default(0);
            $t->unsignedSmallInteger('investor_profit_bps');
            $t->unsignedSmallInteger('business_profit_bps');
            $t->unsignedBigInteger('expected_revenue')->nullable();
            $t->unsignedBigInteger('expected_expenses')->nullable();
            $t->text('business_plan')->nullable();
            $t->text('loss_terms')->nullable();
            $t->bigInteger('actual_net_result')->nullable(); // signed: profit or loss
            $t->timestamps();
        });

        Schema::create('musharakah_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('total_capital');
            $t->unsignedBigInteger('investor_contribution');
            $t->unsignedBigInteger('business_contribution');
            $t->unsignedSmallInteger('investor_ownership_bps');
            $t->unsignedSmallInteger('business_ownership_bps');
            $t->unsignedSmallInteger('investor_profit_bps');
            $t->unsignedSmallInteger('business_profit_bps');
            $t->string('loss_allocation_basis', 20)->default('CAPITAL_RATIO');
            $t->text('project_activity')->nullable();
            $t->text('financial_assumptions')->nullable();
            $t->bigInteger('actual_net_result')->nullable();
            $t->timestamps();
        });

        Schema::create('murabaha_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('stage', 20)->default('REQUESTED')->index();
            $t->unsignedBigInteger('purchase_cost');
            $t->unsignedBigInteger('sale_profit');
            $t->unsignedBigInteger('sale_price');
            $t->unsignedSmallInteger('installments_count')->default(1);
            $t->text('delivery_terms')->nullable();
            $t->text('payment_terms')->nullable();
            $t->timestamps();
        });

        Schema::create('investments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('status', 20)->default('PENDING')->index();
            $t->string('idempotency_key', 100)->unique();
            $t->timestamp('invested_at')->nullable();
            $t->date('maturity_date')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->index(['investor_id', 'status']);
            $t->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['investments', 'murabaha_contracts', 'musharakah_contracts', 'mudarabah_contracts', 'contracts'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
