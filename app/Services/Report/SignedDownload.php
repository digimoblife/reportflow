<?php

namespace App\Services\Report;

use App\Models\ReportFile;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of report files (PRD §56, CLAUDE.md rule 10): only through a signed, expiring URL that was created for a
 * user who owns the report, and re-checked when it is used. Files sit on a private disk; nothing is publicly addressable.
 */
class SignedDownload
{
    public function __construct(private readonly UserContext $context) {}

    /**
     * Must be called inside the user's context (the lookup is user-scoped, so another user's file is "not found").
     */
    public function url(int $fileId, User $user): ?string
    {
        if (ReportFile::query()->find($fileId) === null) {
            return null;
        }

        return URL::temporarySignedRoute('reports.files.download', now()->addMinutes((int) config('reports.download_ttl_minutes')), ['file' => $fileId, 'u' => $user->id]);
    }

    /**
     * The file as a download response, or null when the file is gone or does not belong to $userId.
     */
    public function response(int $fileId, int $userId): ?StreamedResponse
    {
        $file = $this->context->runAs($userId, fn () => ReportFile::query()->with('reportVersion.report')->find($fileId));

        if ($file === null || ! Storage::disk('reports')->exists($file->file_path)) {
            return null;
        }

        $title = (string) ($file->reportVersion->content['title'] ?? 'report');
        $name = Str::slug($title).'-v'.$file->reportVersion->version_no.'.'.$file->format->value;

        return Storage::disk('reports')->download($file->file_path, $name, [
            'Content-Type' => $file->format->value === 'pdf' ? 'application/pdf' : 'text/markdown; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
