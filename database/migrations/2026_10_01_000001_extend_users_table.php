<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §49 users. Email/password stay NOT NULL for the local-only Filament login.
 * TODO(M5): revisit email/password once the Telegram Login Widget replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->nullable()->unique();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->enum('default_language', ['id', 'en'])->default('id');
            $table->jsonb('workdays')->default(new Expression("'[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\"]'::jsonb"));
            $table->boolean('reminders_enabled')->default(true);

            $table->timestampTz('email_verified_at')->nullable()->change();
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();

            $table->dropUnique(['telegram_user_id']);
            $table->dropColumn(['telegram_user_id', 'timezone', 'default_language', 'workdays', 'reminders_enabled']);
        });
    }
};
