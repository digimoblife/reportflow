<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §15, §16, §49 activities.
 * (task_id, project_id) references tasks (id, project_id) ON UPDATE CASCADE, so moving a task to
 * another project moves its activities and a mismatching project_id cannot be inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('activity_type', [
                'request', 'investigation', 'development', 'configuration', 'bug_fix', 'testing', 'deployment',
                'communication', 'research', 'documentation', 'milestone', 'blocker', 'resolution', 'follow_up', 'other',
            ]);
            $table->text('summary');
            $table->jsonb('content_structured')->default(new Expression("'{}'::jsonb"));
            // Calendar date in the user's timezone, not an instant.
            $table->date('activity_date');
            $table->enum('date_precision', ['day', 'week', 'month'])->default('day');
            $table->enum('source', ['telegram', 'dashboard', 'report_edit', 'manual']);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['task_id', 'project_id'], 'activities_task_project_foreign')
                ->references(['id', 'project_id'])->on('tasks')
                ->cascadeOnUpdate()->restrictOnDelete();

            $table->index(['project_id', 'activity_date']);
            $table->index(['task_id', 'activity_date']);
            $table->index('inbound_message_id');
        });

        DB::statement('CREATE INDEX activities_content_structured_gin_index ON activities USING gin (content_structured)');
        DB::statement('CREATE INDEX activities_summary_trgm_index ON activities USING gin (summary gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
