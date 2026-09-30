<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4: what processing a message produced, per item (applied / pending / rejected, ids, versions), plus the
 * confirmation message id. Drives the confirmation text, the correction buttons, undo and idempotency.
 * Holds ids, codes and titles from our own database only, never raw message text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->jsonb('outcome')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
