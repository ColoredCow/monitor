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
        Schema::create('monitor_daily_check_metrics', function (Blueprint $table) {
            $table->id();
            // monitors.id is an unsigned INT (increments()), so the FK column must match its width.
            $table->unsignedInteger('monitor_id');
            $table->foreign('monitor_id')->references('id')->on('monitors')->cascadeOnDelete();
            $table->string('check_type', 50);
            $table->date('date');
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('successful_checks')->default(0);
            $table->unsignedInteger('warning_checks')->default(0);
            $table->unsignedInteger('failed_checks')->default(0);
            $table->decimal('success_ratio', 5, 2)->default(0);
            $table->string('worst_status', 50)->default('unknown');
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->unsignedInteger('p95_response_time_ms')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['monitor_id', 'check_type', 'date', 'timezone'],
                'mdcm_monitor_type_date_timezone_unique'
            );
            $table->index(['monitor_id', 'check_type', 'date'], 'mdcm_monitor_type_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_daily_check_metrics');
    }
};
