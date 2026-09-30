<?php

use Illuminate\Contracts\Queue\ShouldQueue;

// Guard rails for M2 (CLAUDE.md rules 2 and 6).

arch('no debugging output that could print user text')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'var_export', 'error_log', 'ray'])
    ->not->toBeUsed();

arch('the worklog service layer knows nothing about a specific channel')
    ->expect('App\Services\Worklog')
    ->not->toUse(['App\Services\Telegram', 'App\Http', 'Illuminate\Http\Request']);

arch('redaction has no dependency on channels or the database')
    ->expect('App\Services\Redaction')
    ->not->toUse(['App\Services\Telegram', 'App\Models', 'Illuminate\Support\Facades\DB']);

arch('the AI seam does not depend on Telegram or models')
    ->expect('App\Services\Ai')
    ->not->toUse(['App\Services\Telegram', 'App\Models']);

arch('queued jobs are queueable and carry no Eloquent models or raw text')
    ->expect('App\Jobs')
    ->classes->toImplement(ShouldQueue::class)
    ->ignoring('App\Jobs\Middleware');

arch('only the Telegram client talks to the Telegram HTTP API')
    ->expect('Illuminate\Support\Facades\Http')
    ->toOnlyBeUsedIn('App\Services\Telegram\HttpTelegramClient');

arch('controllers stay thin: no models or redaction inside')
    ->expect('App\Http\Controllers')
    ->not->toUse(['App\Models', 'App\Services\Redaction']);
