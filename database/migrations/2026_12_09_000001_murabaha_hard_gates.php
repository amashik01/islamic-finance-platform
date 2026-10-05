<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Murabaha hard gates: the promise record, the seller's risk-bearing confirmation, qabd type, and the executed sale agreement link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('murabaha_promises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('murabaha_contract_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('promise_type', 30);          // UNILATERAL | BILATERAL_WITH_OPTION  (a mutual promise without an option is refused)
            $t->string('promisor', 20);
            $t->string('option_holder', 10)->nullable();
            $t->text('conditions')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recorded_at');
            $t->timestamps();
        });

        Schema::table('murabaha_purchases', function (Blueprint $t) {
            $t->string('qabd_type', 15)->nullable();                   // ACTUAL | CONSTRUCTIVE
            $t->date('risk_confirmed_on')->nullable();                 // the seller has borne the asset's risk through this date
            $t->foreignId('risk_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('risk_notes')->nullable();
            $t->foreignId('acting_wakil_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('murabaha_sales', function (Blueprint $t) {
            $t->foreignId('sale_document_id')->nullable()->constrained('contract_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            Schema::table('murabaha_sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('sale_document_id'));
            Schema::table('murabaha_purchases', function (Blueprint $t) {
                $t->dropConstrainedForeignId('risk_confirmed_by');
                $t->dropConstrainedForeignId('acting_wakil_id');
                $t->dropColumn(['qabd_type', 'risk_confirmed_on', 'risk_notes']);
            });
        }
        Schema::dropIfExists('murabaha_promises');
    }
};
