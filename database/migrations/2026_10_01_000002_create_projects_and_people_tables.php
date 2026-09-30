<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §17, §49 projects and people.
 * projects.report_template_id is added in a later migration (circular with report_templates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->jsonb('aliases')->default(new Expression("'[]'::jsonb"));
            $table->text('description')->nullable();
            // null = inherit users.default_language
            $table->enum('default_language', ['id', 'en'])->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestampsTz();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->jsonb('aliases')->default(new Expression("'[]'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
        Schema::dropIfExists('projects');
    }
};
