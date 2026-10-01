<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 (PRD §78): operational facts the other tables do not hold, kept append-only and free of user text (type + small
 * codes/counters only); and the moment a message finished processing, so the < 10 s target can be measured (§73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 64);
            $table->jsonb('context')->default(new Expression("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
        });

        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->timestampTz('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });

        Schema::dropIfExists('system_events');
    }
};
