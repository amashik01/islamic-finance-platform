<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive. The structured, aqd-specific terms captured by the contract-specific forms (parties, permitted and prohibited
     * activities, governance, promise, Qabd plan ...) and the version of the form they were captured with. A contract with a
     * NULL aqd_form_version predates the aqd forms and is LEGACY: it cannot be submitted until its terms are completed.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->json('aqd_terms')->nullable();
            $t->string('aqd_form_version', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropIndex(['aqd_form_version']);
            $t->dropColumn(['aqd_terms', 'aqd_form_version']);
        });
    }
};
