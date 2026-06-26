<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            // Drop the SET NULL foreign key (incompatible with NOT NULL) and replace
            // with a RESTRICT constraint that is compatible with a NOT NULL column.
            $table->dropForeign(['organization_id']);
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            // RESTRICT blocks organization deletion while monitors reference it; revisit
            // cascade behavior (e.g. CASCADE or SOFT-DELETE) if org deletion is added in v2+.
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            // RESTRICT blocks organization deletion while groups reference it; revisit
            // cascade behavior (e.g. CASCADE or SOFT-DELETE) if org deletion is added in v2+.
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }
};
