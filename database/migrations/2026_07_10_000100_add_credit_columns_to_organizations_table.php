<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Signed on purpose: checks in flight when the balance crosses zero
            // may push it a few credits negative (bounded by one scheduler tick).
            $table->bigInteger('credit_balance')->default(0)->after('slug');
            $table->string('credit_warning_level', 20)->default('none')->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['credit_balance', 'credit_warning_level']);
        });
    }
};
