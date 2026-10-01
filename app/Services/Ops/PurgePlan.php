<?php

namespace App\Services\Ops;

/**
 * What a purge would delete (PRD §57): ids per table and the report files on disk. Built read-only; executed by Purger.
 */
final class PurgePlan
{
    /** Deletion order: children before parents. */
    public const TABLES = [
        'report_files', 'report_versions', 'reports', 'ai_interactions', 'corrections', 'reminder_instances', 'reminder_rules',
        'task_people', 'task_events', 'activities', 'tasks', 'report_templates', 'projects', 'inbound_messages', 'people',
    ];

    /**
     * @param  array<string, list<int>>  $ids
     * @param  list<string>  $files  paths on the reports disk
     * @param  array<string, int>  $notes  things the purge cannot reach on its own (counts only)
     */
    public function __construct(
        public readonly string $target,
        public readonly array $ids,
        public readonly array $files,
        public readonly array $notes = [],
    ) {}

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = count($this->ids[$table] ?? []);
        }

        return array_filter($counts);
    }

    public function isEmpty(): bool
    {
        return $this->counts() === [];
    }
}
