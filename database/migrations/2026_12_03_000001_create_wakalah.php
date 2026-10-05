<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wakalah (agency) support. Additive only; no financial table is touched and no ledger movement is created.
     *  - wakil_profiles: the registered Wakil's organisation profile and verification state (existing KycStatus values).
     *  - wakalah_appointments: one row per (project, Wakalah role) appointment, with its lifecycle and who decided it.
     *  - projects.wakil_id: the Project's appointed Wakil; restrictOnDelete so a Wakil with a live appointment cannot vanish.
     */
    public function up(): void
    {
        Schema::create('wakil_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('display_name', 150);
            $t->string('kyc_status', 20)->default('PENDING')->index();   // same values as Investor/Business KycStatus
            $t->timestamp('kyc_reviewed_at')->nullable();
            $t->string('status', 20)->default('ACTIVE')->index();       // ACTIVE | SUSPENDED (eligibility switch managed by staff)
            $t->timestamps();
        });

        Schema::create('wakalah_appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('wakil_id')->constrained('users')->restrictOnDelete();
            $t->string('wakalah_role', 30)->nullable();                 // null only where the contract defines no Wakalah roles
            $t->string('slot', 30);                                     // wakalah_role or 'GENERAL': the thing a Wakil is appointed for
            $t->string('status', 20)->default('PROPOSED')->index();     // PROPOSED | CONFIRMED | REVOKED
            $t->boolean('is_current')->nullable();                      // 1 while live, NULL once revoked: unique index allows history
            $t->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('appointed_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revocation_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'slot', 'is_current'], 'wakalah_one_current_per_slot');
        });

        Schema::table('projects', function (Blueprint $t) {
            $t->foreignId('wakil_id')->nullable()->after('reviewer_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->dropConstrainedForeignId('wakil_id');
        });
        Schema::dropIfExists('wakalah_appointments');
        Schema::dropIfExists('wakil_profiles');
    }
};
