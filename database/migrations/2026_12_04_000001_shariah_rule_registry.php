<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive. The Shariah rule registry (governance records of the rules the code relies on) and a LEGACY marker for
     * Musharakah contracts that were created with the now-frozen agreed-loss-ratio exception. No ledger row is touched.
     */
    public function up(): void
    {
        Schema::create('shariah_rules', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60);
            $t->unsignedInteger('version')->default(1);
            $t->string('aqd_type', 20)->index();           // MUDARABAH | MUSHARAKAH | MURABAHA | WAKALAH | GENERAL | ACCOUNTING
            $t->string('title');
            $t->text('rule_text');                          // a paraphrase, never presented as a quotation
            $t->text('guidance_text')->nullable();
            $t->string('classification', 30);               // MANDATORY | PROHIBITED | PERMISSIBLE | RECOMMENDED | DISPUTED | POLICY_CHOICE | REQUIRES_SCHOLAR_REVIEW
            $t->string('source_type', 30);
            $t->string('source_name');
            $t->string('standard_code', 40)->nullable();
            $t->string('clause_reference')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->string('verification', 20);                 // TEXT_READ | VIA_SUMMARY | SECONDARY | UNVERIFIED
            $t->string('status', 20)->default('UNDER_REVIEW')->index();   // DRAFT | UNDER_REVIEW | APPROVED | REJECTED | SUPERSEDED
            $t->string('scholar_review_status', 20)->default('PENDING');
            $t->text('system_effect')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['code', 'version']);
        });

        Schema::table('musharakah_contracts', function (Blueprint $t) {
            $t->boolean('legacy_loss_exception')->default(false)->after('loss_exception_approved_at');
        });
        // Mark, never rewrite: contracts that already carry the frozen exception stay settleable under their stored terms.
        DB::table('musharakah_contracts')->where('loss_allocation_basis', 'AGREED_RATIO')->update(['legacy_loss_exception' => true]);
    }

    public function down(): void
    {
        Schema::table('musharakah_contracts', function (Blueprint $t) {
            $t->dropColumn('legacy_loss_exception');
        });
        Schema::dropIfExists('shariah_rules');
    }
};
