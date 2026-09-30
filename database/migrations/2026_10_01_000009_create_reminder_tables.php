<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §25, §27, §29, §34, §49 reminder_rules and reminder_instances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', ['daily_worklog', 'monthly_report']);
            $table->jsonb('schedule')->default(new Expression("'{}'::jsonb"));
            $table->jsonb('config')->default(new Expression("'{}'::jsonb"));
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        // One rule per (user, project-or-global, type); NULLS NOT DISTINCT needs PostgreSQL 15+.
        DB::statement('CREATE UNIQUE INDEX reminder_rules_user_project_type_unique ON reminder_rules (user_id, project_id, type) NULLS NOT DISTINCT');

        Schema::create('reminder_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('next_run_at')->nullable();
            $table->enum('status', ['scheduled', 'sent', 'acknowledged', 'snoozed', 'completed', 'dismissed', 'cancelled'])->default('scheduled');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('action_taken', 64)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_run_at']);
            $table->index('reminder_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_instances');
        Schema::dropIfExists('reminder_rules');
    }
};
