<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD Scrum: store daily scrum standup entries per member and project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrum_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('scrum_date');
            $table->text('what_have_done')->nullable();
            $table->text('issue')->nullable();
            $table->text('what_to_do_next')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'project_id', 'scrum_date']);
            $table->index(['user_id', 'person_id', 'scrum_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrum_entries');
    }
};
