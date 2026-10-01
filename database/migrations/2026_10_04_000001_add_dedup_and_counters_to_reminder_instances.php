<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6: one daily reminder per rule per local day (a scheduler that runs twice, or a retried job, cannot double it),
 * and counters for the "3 reminders per day" and "3 snoozes" limits (PRD §28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_instances', function (Blueprint $table) {
            $table->date('reminder_date')->nullable();
            $table->smallInteger('send_count')->default(0);
            $table->smallInteger('snooze_count')->default(0);

            $table->unique(['reminder_rule_id', 'reminder_date']);
        });
    }

    public function down(): void
    {
        Schema::table('reminder_instances', function (Blueprint $table) {
            $table->dropUnique(['reminder_rule_id', 'reminder_date']);
            $table->dropColumn(['reminder_date', 'send_count', 'snooze_count']);
        });
    }
};
