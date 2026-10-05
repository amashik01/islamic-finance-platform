<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('currency', 3)->default('BDT');
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['user_id', 'currency']);
        });

        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type', 30)->index();
            $t->string('name');
            $t->string('currency', 3)->default('BDT');
            $t->string('normal_side', 6); // DEBIT|CREDIT
            $t->foreignId('wallet_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // Cached balance in minor units on the normal side. Mutated only by LedgerService under row lock.
            $t->bigInteger('balance')->default(0);
            $t->timestamps();
            $t->index(['wallet_id', 'type']);
        });

        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->string('idempotency_key', 100)->nullable()->unique();
            $t->string('type', 30)->index();
            $t->string('status', 20)->default('POSTED')->index();
            $t->string('currency', 3)->default('BDT');
            $t->unsignedBigInteger('amount');
            $t->string('description')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->index();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('investment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reverses_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->json('meta')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $t->string('direction', 6);
            $t->unsignedBigInteger('amount');
            $t->bigInteger('balance_after');
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['ledger_account_id', 'id']);
        });

        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reference')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('method', 30)->default('BANK_TRANSFER');
            $t->string('payment_reference')->nullable();
            $t->string('status', 20)->default('PENDING')->index();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100)->unique();
            $t->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reference')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('BDT');
            $t->string('status', 20)->default('PENDING')->index();
            $t->string('bank_account_number')->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100)->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['withdrawals', 'deposits', 'ledger_entries', 'transactions', 'ledger_accounts', 'wallets'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
