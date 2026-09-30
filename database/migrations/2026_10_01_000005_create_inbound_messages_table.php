<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §23 (idempotency), §48 (raw input preservation), §49 inbound_messages.
 * text holds the message AFTER redaction; secrets are never stored.
 * reply_message_id is not in PRD §49 (gap, see docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['telegram', 'dashboard']);
            $table->string('idempotency_key', 191)->unique();
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->bigInteger('reply_message_id')->nullable();
            $table->text('text');
            $table->jsonb('attachments')->default(new Expression("'[]'::jsonb"));
            $table->timestampTz('received_at');
            $table->timestampTz('edited_at')->nullable();
            $table->enum('status', ['received', 'processing', 'processed', 'needs_clarification', 'failed'])->default('received');
            $table->text('error')->nullable();
            $table->unsignedInteger('reprocess_count')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE inbound_messages ADD CONSTRAINT inbound_messages_telegram_ids_required CHECK (source <> 'telegram' OR (telegram_chat_id IS NOT NULL AND telegram_message_id IS NOT NULL))");
        DB::statement('ALTER TABLE inbound_messages ADD CONSTRAINT inbound_messages_reprocess_count_non_negative CHECK (reprocess_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
    }
};
