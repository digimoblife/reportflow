<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §21, §47, §49 task_events and corrections. Both are append-only (created_at only).
 * JSON shapes of task_events.from_value / to_value are documented in docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->enum('event_type', ['created', 'status_changed', 'title_changed', 'moved', 'merged', 'reopened', 'undone']);
            $table->jsonb('from_value')->nullable();
            $table->jsonb('to_value')->nullable();
            $table->enum('actor', ['ai', 'user', 'system']);
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
            $table->index('inbound_message_id');
        });

        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('correction_type', ['undo', 'move_task', 'change_status', 'change_project', 'edit_date']);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('inbound_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrections');
        Schema::dropIfExists('task_events');
    }
};
