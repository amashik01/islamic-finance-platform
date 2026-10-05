<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive. A Wakalah appointment now records WHO the principal (Muwakkil) is, the scope and authority, the Wakil's
     * acceptance and an appointment-level Shariah review. Existing rows keep working: new columns are nullable and legacy
     * PROPOSED / CONFIRMED / REVOKED values stay valid.
     */
    public function up(): void
    {
        Schema::table('wakalah_appointments', function (Blueprint $t) {
            $t->string('muwakkil', 30)->nullable()->after('wakil_id');            // PLATFORM | BUSINESS ; null = LEGACY (principal was never recorded)
            $t->foreignId('muwakkil_user_id')->nullable()->after('muwakkil')->constrained('users')->nullOnDelete();
            $t->text('scope')->nullable();
            $t->json('authority')->nullable();                                    // the specific acts the Wakil may perform
            $t->foreignId('contract_id')->nullable()->after('project_id')->constrained()->nullOnDelete();   // underlying aqd
            $t->unsignedInteger('version')->default(1);
            $t->string('evidence_reference', 200)->nullable();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->foreignId('shariah_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('shariah_reviewed_at')->nullable();
            $t->string('shariah_decision', 20)->nullable();
            $t->text('shariah_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wakalah_appointments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('muwakkil_user_id');
            $t->dropConstrainedForeignId('contract_id');
            $t->dropConstrainedForeignId('accepted_by');
            $t->dropConstrainedForeignId('shariah_reviewer_id');
            $t->dropColumn(['muwakkil', 'scope', 'authority', 'version', 'evidence_reference', 'accepted_at', 'rejected_at', 'rejection_reason', 'shariah_reviewed_at', 'shariah_decision', 'shariah_notes']);
        });
    }
};
