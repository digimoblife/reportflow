<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys that could not be declared at create time because the tables reference each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('report_template_id')->nullable()->after('default_language')
                ->constrained('report_templates')->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->after('status')
                ->constrained('report_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_template_id');
        });
    }
};
