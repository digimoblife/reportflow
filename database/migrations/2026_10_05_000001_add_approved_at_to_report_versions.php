<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7: which version was approved (PRD §43: an approved version is immutable). A report is "approved" while its current
 * version is an approved one; later edits become new versions and leave the approved one untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_versions', function (Blueprint $table) {
            $table->timestampTz('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_versions', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
