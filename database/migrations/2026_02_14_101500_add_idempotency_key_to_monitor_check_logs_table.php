<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monitor_check_logs', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('monitor_id');
            $table->unique('idempotency_key', 'mcl_idempotency_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitor_check_logs', function (Blueprint $table) {
            $table->dropUnique('mcl_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
