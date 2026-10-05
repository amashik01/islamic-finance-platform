<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('wakil_id')
                ->nullable()
                ->after('business_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->index(['wakil_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['wakil_id']);
            $table->dropIndex(['wakil_id', 'status']);
            $table->dropColumn('wakil_id');
        });
    }
};
