<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_usage_daily', function (Blueprint $table) {
            // The nightly rollup filters by date alone; the unique index
            // starts with organization_id, so date was an unindexed scan.
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('credit_usage_daily', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
    }
};
