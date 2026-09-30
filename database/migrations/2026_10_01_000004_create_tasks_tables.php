<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §14, §23 (optimistic locking), §49 tasks and task_people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Free-form, filled by AI later; intentionally not an enum (docs/DECISIONS.md).
            $table->string('type', 64)->nullable();
            // No DB default: the writer must choose the initial status explicitly.
            $table->enum('status', ['draft', 'open', 'in_progress', 'waiting', 'blocked', 'completed', 'cancelled']);
            $table->enum('waiting_reason', ['client', 'vendor', 'api', 'launch', 'confirmation'])->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['project_id', 'status']);
            // Target of the activities (task_id, project_id) composite foreign key.
            $table->unique(['id', 'project_id']);
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_waiting_reason_only_when_waiting CHECK (status = 'waiting' OR waiting_reason IS NULL)");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_version_positive CHECK (version >= 1)');
        DB::statement('CREATE INDEX tasks_title_trgm_index ON tasks USING gin (title gin_trgm_ops)');

        Schema::create('task_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->enum('role', ['requester', 'assignee', 'stakeholder']);
            $table->timestampsTz();

            $table->unique(['task_id', 'person_id', 'role']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_people');
        Schema::dropIfExists('tasks');
    }
};
