<?php

namespace App\Services\Scrum;

use Illuminate\Support\Facades\Cache;

/**
 * Manages conversational state for interactive Telegram /scrum sessions (PRD Scrum §4).
 * Stored in cache with TTL 1800 seconds (30 minutes).
 */
class ScrumSessionManager
{
    private const TTL_SECONDS = 1800;

    /**
     * @return array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }
     */
    public function start(int $userId, int $projectId, ?int $personId = null, ?string $personName = null): array
    {
        $session = [
            'project_id' => $projectId,
            'person_id' => $personId,
            'person_name' => $personName,
            'awaiting_field' => null,
            'completed_member_ids' => [],
            'fields' => [
                'what_have_done' => null,
                'issue' => null,
                'what_to_do_next' => null,
            ],
        ];

        Cache::put($this->key($userId), $session, self::TTL_SECONDS);

        return $session;
    }

    /**
     * @return array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }|null
     */
    public function get(int $userId): ?array
    {
        $state = Cache::get($this->key($userId));

        if (! is_array($state) || ! isset($state['project_id'], $state['fields'])) {
            return null;
        }

        /** @var array{
         *     project_id: int,
         *     person_id: int|null,
         *     person_name: string|null,
         *     awaiting_field: string|null,
         *     completed_member_ids: list<int>,
         *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
         * } $state */
        return $state;
    }

    /**
     * @return array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }|null
     */
    public function selectMember(int $userId, int $personId, string $personName): ?array
    {
        $session = $this->get($userId);
        if ($session === null) {
            return null;
        }

        $session['person_id'] = $personId;
        $session['person_name'] = $personName;
        $session['awaiting_field'] = null;
        $session['fields'] = [
            'what_have_done' => null,
            'issue' => null,
            'what_to_do_next' => null,
        ];

        Cache::put($this->key($userId), $session, self::TTL_SECONDS);

        return $session;
    }

    public function setAwaitingField(int $userId, ?string $field): void
    {
        $session = $this->get($userId);
        if ($session === null) {
            return;
        }

        $session['awaiting_field'] = $field;
        Cache::put($this->key($userId), $session, self::TTL_SECONDS);
    }

    /**
     * @param  'what_have_done'|'issue'|'what_to_do_next'|string  $field
     * @return array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }|null
     */
    public function setFieldValue(int $userId, string $field, string $value): ?array
    {
        $session = $this->get($userId);
        if ($session === null) {
            return null;
        }

        $fields = $session['fields'];
        if ($field === 'what_have_done') {
            $fields['what_have_done'] = trim($value);
        } elseif ($field === 'issue') {
            $fields['issue'] = trim($value);
        } elseif ($field === 'what_to_do_next') {
            $fields['what_to_do_next'] = trim($value);
        }

        $session['fields'] = $fields;
        $session['awaiting_field'] = null;

        Cache::put($this->key($userId), $session, self::TTL_SECONDS);

        return $session;
    }

    /**
     * @return array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }|null
     */
    public function markMemberCompleted(int $userId, int $personId): ?array
    {
        $session = $this->get($userId);
        if ($session === null) {
            return null;
        }

        if (! in_array($personId, $session['completed_member_ids'], true)) {
            $session['completed_member_ids'][] = $personId;
        }

        $session['person_id'] = null;
        $session['person_name'] = null;
        $session['awaiting_field'] = null;
        $session['fields'] = [
            'what_have_done' => null,
            'issue' => null,
            'what_to_do_next' => null,
        ];

        Cache::put($this->key($userId), $session, self::TTL_SECONDS);

        return $session;
    }

    public function isAwaitingInput(int $userId): bool
    {
        $session = $this->get($userId);

        return $session !== null && $session['awaiting_field'] !== null;
    }

    public function clear(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "tg:scrum-session:{$userId}";
    }
}
