<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * "The next message is the instruction for this report section" (PRD §42, edit via instruction from Telegram).
 * Cache only, short-lived: losing it just means the person taps "Edit via instruksi" again. The instruction text
 * itself is never kept here, only ids.
 */
class AwaitingReportInstruction
{
    private const TTL_SECONDS = 600;

    public function await(int $userId, int $reportId, int $versionNo, string $sectionKey): void
    {
        Cache::put($this->key($userId), ['report_id' => $reportId, 'version_no' => $versionNo, 'section' => $sectionKey], self::TTL_SECONDS);
    }

    /**
     * @return array{report_id: int, version_no: int, section: string}|null
     */
    public function get(int $userId): ?array
    {
        $state = Cache::get($this->key($userId));

        return is_array($state) && isset($state['report_id'], $state['version_no'], $state['section']) ? $state : null;
    }

    public function clear(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "tg:report-instruction:{$userId}";
    }
}
