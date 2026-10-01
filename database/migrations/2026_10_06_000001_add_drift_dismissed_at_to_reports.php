<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8: when the person chose "Abaikan" for a report that went out of date (PRD §43 late entries). Later drift is measured
 * from this moment, so an ignored change does not nag again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestampTz('drift_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('drift_dismissed_at');
        });
    }
};
