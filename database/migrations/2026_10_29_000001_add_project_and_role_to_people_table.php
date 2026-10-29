<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD Scrum: link people to projects and add role titles for team member tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('role_title', 100)->nullable()->after('name');
            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'project_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('role_title');
        });
    }
};
