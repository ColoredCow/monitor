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
        Schema::create('monitor_check_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('check_type', 50);
            $table->string('status', 50)->default('unknown');
            $table->timestamp('checked_at');
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('message')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'check_type', 'checked_at'], 'mcl_monitor_type_checked_at_idx');
            $table->index('checked_at', 'mcl_checked_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_check_logs');
    }
};
