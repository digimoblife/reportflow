<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §23 (snapshot, optimistic locking), §43, §44, §49 reports, report_versions, report_files.
 * reports.current_version_id is added in a later migration (circular with report_versions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['monthly', 'weekly', 'custom', 'incident']);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('language', ['id', 'en']);
            $table->foreignId('template_id')->nullable()->constrained('report_templates')->restrictOnDelete();
            $table->enum('status', ['draft', 'generating', 'in_review', 'approved', 'outdated', 'cancelled'])->default('draft');
            $table->timestampTz('generation_lock_until')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'period_start', 'period_end']);
        });

        DB::statement('ALTER TABLE reports ADD CONSTRAINT reports_period_order CHECK (period_end >= period_start)');
        DB::statement("CREATE UNIQUE INDEX reports_active_period_unique ON reports (project_id, type, period_start, period_end, language) WHERE status <> 'cancelled'");

        Schema::create('report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->jsonb('content')->default(new Expression("'{}'::jsonb"));
            $table->timestampTz('data_snapshot_at');
            $table->jsonb('source_activity_ids')->default(new Expression("'[]'::jsonb"));
            $table->enum('created_by', ['ai_generate', 'instruction_edit', 'user_edit']);
            $table->enum('source_channel', ['telegram', 'dashboard'])->nullable();
            $table->text('instruction')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['report_id', 'version_no']);
        });

        DB::statement('ALTER TABLE report_versions ADD CONSTRAINT report_versions_version_positive CHECK (version >= 1)');

        Schema::create('report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_version_id')->constrained()->restrictOnDelete();
            $table->enum('format', ['pdf', 'md', 'docx']);
            $table->string('file_path');
            $table->char('checksum', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['report_version_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
        Schema::dropIfExists('report_versions');
        Schema::dropIfExists('reports');
    }
};
