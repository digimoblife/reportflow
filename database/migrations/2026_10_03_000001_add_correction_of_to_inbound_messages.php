<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4f: a reply to a confirmation is stored as its own inbound message (raw input is preserved, PRD §48) and points at
 * the message it corrects. Processing it undoes the old result and applies the corrected one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->foreignId('correction_of_id')->nullable()->constrained('inbound_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_of_id');
        });
    }
};
