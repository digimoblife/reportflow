<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates every MVP table from PRD §49', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'users', 'projects', 'people', 'tasks', 'task_people', 'inbound_messages', 'activities', 'task_events',
    'corrections', 'report_templates', 'reports', 'report_versions', 'report_files', 'reminder_rules',
    'reminder_instances', 'ai_interactions',
]);

it('does not create the Phase 3 incidents table', function () {
    expect(Schema::hasTable('incidents'))->toBeFalse();
});

it('stores every timestamp column as timestamptz', function () {
    $naive = DB::select(<<<'SQL'
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND data_type = 'timestamp without time zone'
          AND table_name NOT IN ('password_reset_tokens', 'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions')
    SQL);

    expect($naive)->toBe([]);
});

it('uses a UTC database session', function () {
    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('UTC');
});

it('has the recommended and justified indexes', function (string $table, string $index, string $definitionFragment) {
    $row = DB::selectOne('SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = ? AND indexname = ?', ['public', $table, $index]);

    expect($row)->not->toBeNull()
        ->and($row->indexdef)->toContain($definitionFragment);
})->with([
    ['activities', 'activities_project_id_activity_date_index', '(project_id, activity_date)'],
    ['tasks', 'tasks_project_id_status_index', '(project_id, status)'],
    ['activities', 'activities_content_structured_gin_index', 'USING gin (content_structured)'],
    ['tasks', 'tasks_title_trgm_index', 'gin (title gin_trgm_ops)'],
    ['activities', 'activities_summary_trgm_index', 'gin (summary gin_trgm_ops)'],
    ['inbound_messages', 'inbound_messages_user_id_status_index', '(user_id, status)'],
    ['inbound_messages', 'inbound_messages_idempotency_key_unique', 'UNIQUE'],
    ['users', 'users_telegram_user_id_unique', 'UNIQUE'],
    ['activities', 'activities_task_id_activity_date_index', '(task_id, activity_date)'],
    ['activities', 'activities_inbound_message_id_index', '(inbound_message_id)'],
    ['task_events', 'task_events_task_id_created_at_index', '(task_id, created_at)'],
    ['reports', 'reports_active_period_unique', "WHERE ((status)::text <> 'cancelled'::text)"],
    ['reminder_rules', 'reminder_rules_user_project_type_unique', 'NULLS NOT DISTINCT'],
    ['reminder_instances', 'reminder_instances_status_next_run_at_index', '(status, next_run_at)'],
]);

it('has a version column defaulting to 1 for optimistic locking (PRD §23)', function (string $table) {
    $column = DB::selectOne(
        'SELECT column_default, is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
        [$table, 'version'],
    );

    expect($column->column_default)->toBe('1')
        ->and($column->is_nullable)->toBe('NO');
})->with(['tasks', 'report_versions']);

it('soft deletes tasks and activities', function (string $table) {
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
})->with(['tasks', 'activities']);
