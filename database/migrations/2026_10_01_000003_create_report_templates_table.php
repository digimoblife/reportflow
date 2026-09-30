<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §40, §49 report_templates. project_id null = global template for the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->enum('language', ['id', 'en']);
            $table->string('title_format');
            $table->jsonb('sections')->default(new Expression("'[]'::jsonb"));
            $table->string('blade_view');
            $table->text('stylesheet')->nullable();
            $table->jsonb('formatting_rules')->default(new Expression("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
