<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // nullOnDelete preserves org-level usage history when the purge
            // hard-deletes a monitor.
            $table->unsignedInteger('monitor_id')->nullable();
            $table->foreign('monitor_id')->references('id')->on('monitors')->nullOnDelete();
            $table->string('check_type', 20); // uptime | certificate | domain
            $table->date('date'); // UTC
            $table->unsignedBigInteger('credits')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'monitor_id', 'check_type', 'date'], 'credit_usage_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_usage_daily');
    }
};
