<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract template engine and digital Aqd evidence. Additive. Executed documents and signatures are immutable at the
     * DATABASE level (triggers), not only in the model: a direct UPDATE of an executed agreement or any change to a
     * signature is refused.
     */
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();                 // e.g. MUDARABAH-MASTER
            $t->string('aqd_type', 20)->index();
            $t->string('kind', 30);                           // MASTER_AQD | PARTICIPATION | WAKALAH | MURABAHA_SALE
            $t->string('title');
            $t->timestamps();
        });

        Schema::create('contract_template_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('contract_templates')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('language', 10)->default('en');
            $t->string('status', 20)->default('ACTIVE')->index();      // DRAFT | ACTIVE | SUPERSEDED
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('shariah_review_status', 20)->default('PENDING')->index();   // PENDING | APPROVED | REJECTED | NEEDS_REVISION
            $t->foreignId('shariah_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('shariah_reviewed_at')->nullable();
            $t->text('shariah_notes')->nullable();
            $t->string('content_hash', 64);                   // hash of the clauses; proves a used version was never edited
            $t->timestamps();
            $t->unique(['template_id', 'version']);
        });

        Schema::create('contract_clauses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_version_id')->constrained('contract_template_versions')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->string('code', 60);
            $t->string('heading');
            $t->text('body');                                 // may contain {{placeholders}}
            $t->string('condition', 60)->nullable();          // included only when this data flag is truthy
            $t->json('rule_codes')->nullable();
            $t->timestamps();
            $t->unique(['template_version_id', 'position']);
        });

        Schema::create('contract_documents', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 30)->unique();
            $t->string('kind', 30)->index();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('template_version_id')->constrained('contract_template_versions')->restrictOnDelete();
            $t->unsignedInteger('version_no')->default(1);
            $t->foreignId('party_user_id')->nullable()->constrained('users')->restrictOnDelete();   // participation: the investor; wakalah: the Wakil
            $t->unsignedBigInteger('amount')->nullable();      // participation amount (minor units, BDT)
            $t->string('currency', 3)->default('BDT');
            $t->longText('content');
            $t->json('terms_snapshot');
            $t->string('terms_hash', 64);                      // hash of the terms body without the review block (what a reviewer approves)
            $t->string('document_hash', 64);                   // hash of the full document (what is signed)
            $t->string('status', 24)->default('DRAFT')->index();   // DRAFT | PENDING_SIGNATURE | EXECUTED | SUPERSEDED | CANCELLED
            $t->foreignId('supersedes_id')->nullable()->constrained('contract_documents')->restrictOnDelete();
            $t->foreignId('shariah_review_id')->nullable()->constrained('shariah_reviews')->nullOnDelete();
            $t->foreignId('wakalah_appointment_id')->nullable()->constrained('wakalah_appointments')->nullOnDelete();
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at');
            $t->timestamp('executed_at')->nullable();
            $t->unsignedBigInteger('consumed_by_investment_id')->nullable()->unique();
            $t->timestamps();
            $t->index(['project_id', 'kind', 'status']);
        });

        Schema::create('contract_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_document_id')->constrained('contract_documents')->restrictOnDelete();
            $t->foreignId('signer_user_id')->constrained('users')->restrictOnDelete();
            $t->string('signer_role', 30);
            $t->string('signature_method', 30);
            $t->string('signature_data');                      // the typed name that was signed
            $t->string('document_hash', 64);                   // the exact document hash signed
            $t->string('consent_version', 30);
            $t->string('consent_text_hash', 64);
            $t->json('identity_check');                        // e.g. {"kyc":"APPROVED"} at the time of signing
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('signed_at');
            $t->unique(['contract_document_id', 'signer_user_id', 'signer_role'], 'signature_once_per_role');
        });

        Schema::create('contract_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_document_id')->constrained('contract_documents')->restrictOnDelete();
            $t->foreignId('to_document_id')->nullable()->constrained('contract_documents')->restrictOnDelete();
            $t->string('status', 20)->default('REQUESTED')->index();   // REQUESTED | APPROVED | REJECTED | EXECUTED
            $t->text('reason');
            $t->json('changes');
            $t->boolean('legal_review_required')->default(false);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('shariah_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('shariah_reviewed_at')->nullable();
            $t->text('shariah_notes')->nullable();
            $t->string('legal_reviewed_by', 120)->nullable();
            $t->timestamps();
        });

        Schema::table('shariah_reviews', function (Blueprint $t) {
            $t->string('aqd_type', 20)->nullable();
            $t->foreignId('template_version_id')->nullable()->constrained('contract_template_versions')->nullOnDelete();
            $t->string('reviewed_terms_hash', 64)->nullable();
            $t->unsignedInteger('review_version')->default(1);
            $t->text('conditions')->nullable();
            $t->string('scope', 60)->nullable();              // what exactly was reviewed, e.g. "project terms + MUDARABAH-MASTER v1"
        });

        Schema::table('investments', function (Blueprint $t) {
            $t->foreignId('participation_document_id')->nullable()->unique()->constrained('contract_documents')->restrictOnDelete();
        });

        $this->triggers();
    }

    private function triggers(): void
    {
        $locked = ['content', 'terms_snapshot', 'terms_hash', 'document_hash', 'template_version_id', 'kind', 'project_id', 'contract_id', 'amount', 'party_user_id', 'version_no', 'reference', 'currency'];
        if (DB::getDriverName() === 'mysql') {
            $cond = implode(' OR ', array_map(fn ($c) => "NOT (NEW.`$c` <=> OLD.`$c`)", $locked));
            DB::unprepared("CREATE TRIGGER contract_documents_no_edit BEFORE UPDATE ON contract_documents FOR EACH ROW BEGIN IF OLD.status IN ('EXECUTED','SUPERSEDED') AND ($cond) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Executed contract documents are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER contract_documents_no_delete BEFORE DELETE ON contract_documents FOR EACH ROW BEGIN IF OLD.status IN ('EXECUTED','SUPERSEDED') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Executed contract documents cannot be deleted'; END IF; END");
            DB::unprepared("CREATE TRIGGER contract_signatures_no_update BEFORE UPDATE ON contract_signatures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signatures are immutable'");
            DB::unprepared("CREATE TRIGGER contract_signatures_no_delete BEFORE DELETE ON contract_signatures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signatures cannot be deleted'");
            DB::statement("ALTER TABLE `contract_documents` ADD CONSTRAINT `chk_contract_documents_bdt` CHECK (currency = 'BDT')");
        } else {
            $cond = implode(' OR ', array_map(fn ($c) => "NEW.\"$c\" IS NOT OLD.\"$c\"", $locked));
            DB::unprepared("CREATE TRIGGER contract_documents_no_edit BEFORE UPDATE ON contract_documents WHEN OLD.status IN ('EXECUTED','SUPERSEDED') AND ($cond) BEGIN SELECT RAISE(ABORT, 'Executed contract documents are immutable'); END");
            DB::unprepared("CREATE TRIGGER contract_documents_no_delete BEFORE DELETE ON contract_documents WHEN OLD.status IN ('EXECUTED','SUPERSEDED') BEGIN SELECT RAISE(ABORT, 'Executed contract documents cannot be deleted'); END");
            DB::unprepared("CREATE TRIGGER contract_signatures_no_update BEFORE UPDATE ON contract_signatures BEGIN SELECT RAISE(ABORT, 'Signatures are immutable'); END");
            DB::unprepared("CREATE TRIGGER contract_signatures_no_delete BEFORE DELETE ON contract_signatures BEGIN SELECT RAISE(ABORT, 'Signatures cannot be deleted'); END");
        }
    }

    public function down(): void
    {
        foreach (['contract_documents_no_edit', 'contract_documents_no_delete', 'contract_signatures_no_update', 'contract_signatures_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS $trigger");
        }
        // SQLite cannot drop a column that carries a foreign key; there the rebuilt tables are discarded with the database.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('investments', function (Blueprint $t) {
                $t->dropForeign(['participation_document_id']);
                $t->dropUnique(['participation_document_id']);
                $t->dropColumn('participation_document_id');
            });
            Schema::table('shariah_reviews', function (Blueprint $t) {
                $t->dropForeign(['template_version_id']);
                $t->dropColumn(['template_version_id', 'aqd_type', 'reviewed_terms_hash', 'review_version', 'conditions', 'scope']);
            });
        }
        Schema::dropIfExists('contract_amendments');
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contract_documents');
        Schema::dropIfExists('contract_clauses');
        Schema::dropIfExists('contract_template_versions');
        Schema::dropIfExists('contract_templates');
    }
};
