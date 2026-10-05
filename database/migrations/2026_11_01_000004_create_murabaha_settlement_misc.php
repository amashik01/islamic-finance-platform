<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('murabaha_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('murabaha_contract_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('supplier_name');
            $t->unsignedInteger('quantity')->default(1);
            $t->unsignedBigInteger('unit_cost');
            $t->timestamps();
        });

        Schema::create('murabaha_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('murabaha_contract_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount');
            $t->date('purchased_on')->nullable();
            $t->string('invoice_reference')->nullable();
            $t->date('ownership_acquired_on')->nullable(); // ownership record
            $t->date('possession_on')->nullable();         // possession / qabd record
            $t->text('possession_notes')->nullable();
            $t->timestamps();
        });

        Schema::create('murabaha_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('murabaha_contract_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('purchase_cost');
            $t->unsignedBigInteger('sale_profit');
            $t->unsignedBigInteger('sale_price');
            $t->date('sold_on')->nullable();
            $t->timestamps();
        });

        Schema::create('receivables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('murabaha_sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('total_amount');
            $t->unsignedBigInteger('paid_amount')->default(0);
            $t->string('status', 20)->default('SCHEDULED')->index();
            $t->timestamps();
        });

        Schema::create('payment_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receivable_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->date('due_date')->index();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('paid_amount')->default(0);
            $t->string('status', 20)->default('SCHEDULED')->index();
            $t->timestamps();
            $t->unique(['receivable_id', 'sequence']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('receivable_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('reference')->unique();
            $t->string('idempotency_key', 100)->unique();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->date('paid_on');
            $t->timestamps();
        });

        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('DRAFT')->index();
            $t->string('currency', 3)->default('BDT');
            $t->bigInteger('actual_net_result')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('settlement_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('item_type', 30)->index();
            $t->bigInteger('amount');
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('shariah_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('PENDING')->index();
            $t->text('notes')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->morphs('documentable');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('category', 30)->index();
            $t->string('title');
            $t->string('disk', 20)->default('private');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size');
            $t->unsignedSmallInteger('version')->default(1);
            $t->foreignId('previous_version_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->string('verification_status', 20)->default('PENDING')->index();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action')->index();
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('group', 40)->index();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'documents', 'shariah_reviews', 'settlement_items', 'settlements', 'payments', 'payment_schedules', 'receivables', 'murabaha_sales', 'murabaha_purchases', 'murabaha_assets'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
