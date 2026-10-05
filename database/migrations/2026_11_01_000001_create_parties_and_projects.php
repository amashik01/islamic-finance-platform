<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('kyc_status', 20)->default('NOT_SUBMITTED')->index();
            $t->string('national_id')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('address')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('bank_account_name')->nullable();
            $t->string('bank_account_number')->nullable();
            $t->boolean('bank_verified')->default(false);
            $t->boolean('risk_flag')->default(false);
            $t->boolean('is_demo')->default(false);
            $t->timestamp('kyc_reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('registration_number')->nullable()->unique();
            $t->string('tax_id')->nullable();
            $t->string('industry')->nullable()->index();
            $t->text('description')->nullable();
            $t->string('address')->nullable();
            $t->string('website')->nullable();
            $t->string('kyc_status', 20)->default('NOT_SUBMITTED')->index();
            $t->boolean('is_demo')->default(false);
            $t->timestamp('kyc_reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->string('slug')->unique();
            $t->text('description');
            $t->string('industry')->nullable()->index();
            $t->text('purpose')->nullable();
            $t->string('contract_type', 20)->index();
            // Money columns are integer minor units (paisa).
            $t->unsignedBigInteger('funding_target');
            $t->unsignedBigInteger('funded_amount')->default(0);
            $t->unsignedBigInteger('minimum_amount');
            $t->unsignedSmallInteger('duration_months');
            $t->string('risk_level', 10)->index();
            $t->string('currency', 3)->default('BDT');
            $t->text('key_risks')->nullable();
            $t->json('assumptions')->nullable();
            $t->string('status', 20)->default('DRAFT')->index();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('closing_at')->nullable()->index();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->index(['status', 'contract_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('investors');
    }
};
