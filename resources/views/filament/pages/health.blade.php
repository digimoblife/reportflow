@php
    $m = $this->metrics;
    $t = $m['targets'];
    $pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 1).'%';
    $num = fn ($v, $suffix = '') => $v === null ? '–' : $v.$suffix;
    $badge = fn ($ok) => $ok === null ? 'gray' : ($ok ? 'success' : 'danger');
    $mark = fn ($ok) => $ok === null ? '–' : ($ok ? '✓' : '✗');
@endphp
<x-filament-panels::page>
    <div wire:poll.30s class="space-y-6">
        <div class="flex gap-2">
            <x-filament::button size="xs" :color="$days === 7 ? 'primary' : 'gray'" wire:click="setDays(7)">{{ __('ui.dashboard.health.last_7') }}</x-filament::button>
            <x-filament::button size="xs" :color="$days === 30 ? 'primary' : 'gray'" wire:click="setDays(30)">{{ __('ui.dashboard.health.last_30') }}</x-filament::button>
        </div>

        <x-filament::section :heading="__('ui.dashboard.health.targets')">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th>{{ __('ui.dashboard.health.metric') }}</th><th>{{ __('ui.dashboard.health.value') }}</th><th>{{ __('ui.dashboard.health.target') }}</th><th></th></tr></thead>
                <tbody>
                    <tr><td>{{ __('ui.dashboard.health.correction_rate') }}</td><td>{{ $pct($t['correction_rate']['value']) }}</td><td>&lt; {{ $pct($t['correction_rate']['target']) }}</td><td><x-filament::badge size="sm" :color="$badge($t['correction_rate']['ok'])">{{ $mark($t['correction_rate']['ok']) }}</x-filament::badge></td></tr>
                    <tr><td>{{ __('ui.dashboard.health.worklog_p95') }}</td><td>{{ $num($t['worklog_p95']['value'], ' s') }}</td><td>&lt; {{ $t['worklog_p95']['target'] }} s</td><td><x-filament::badge size="sm" :color="$badge($t['worklog_p95']['ok'])">{{ $mark($t['worklog_p95']['ok']) }}</x-filament::badge></td></tr>
                    <tr><td>{{ __('ui.dashboard.health.report_success') }}</td><td>{{ $pct($t['report_success']['value']) }}</td><td>≥ {{ $pct($t['report_success']['target']) }}</td><td><x-filament::badge size="sm" :color="$badge($t['report_success']['ok'])">{{ $mark($t['report_success']['ok']) }}</x-filament::badge></td></tr>
                    <tr><td>{{ __('ui.dashboard.health.pdf_success') }}</td><td>{{ $pct($t['pdf_success']['value']) }}</td><td>≥ {{ $pct($t['pdf_success']['target']) }}</td><td><x-filament::badge size="sm" :color="$badge($t['pdf_success']['ok'])">{{ $mark($t['pdf_success']['ok']) }}</x-filament::badge></td></tr>
                    <tr><td>{{ __('ui.dashboard.health.ai_failure_rate') }}</td><td>{{ $pct($t['ai_failure_rate']['value']) }}</td><td>≤ {{ $pct($t['ai_failure_rate']['target']) }}</td><td><x-filament::badge size="sm" :color="$badge($t['ai_failure_rate']['ok'])">{{ $mark($t['ai_failure_rate']['ok']) }}</x-filament::badge></td></tr>
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">{{ __('ui.dashboard.health.offline_note') }}</p>
        </x-filament::section>

        <div class="grid gap-6 md:grid-cols-2">
            <x-filament::section :heading="__('ui.dashboard.health.ai')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>{{ __('ui.dashboard.health.requests') }}</dt><dd>{{ $m['ai']['requests'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.failed') }}</dt><dd>{{ $m['ai']['failed'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.latency') }}</dt><dd>{{ $num($m['ai']['p50_ms'], ' ms') }} / {{ $num($m['ai']['p95_ms'], ' ms') }}</dd>
                    <dt>{{ __('ui.dashboard.health.tokens') }}</dt><dd>{{ number_format($m['ai']['tokens_input']) }} / {{ number_format($m['ai']['tokens_output']) }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section :heading="__('ui.dashboard.health.worklog')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>{{ __('ui.dashboard.health.processed') }}</dt><dd>{{ $m['worklog']['processed'] }}</dd>
                    <dt>p50 / p95</dt><dd>{{ $num($m['worklog']['p50_seconds'], ' s') }} / {{ $num($m['worklog']['p95_seconds'], ' s') }}</dd>
                    <dt>{{ __('ui.dashboard.health.applied') }}</dt><dd>{{ $m['corrections']['applied'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.corrections') }}</dt><dd>{{ $m['corrections']['corrections'] }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section :heading="__('ui.dashboard.health.reports')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>{{ __('ui.dashboard.health.generated') }}</dt><dd>{{ $m['reports']['generated'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.failed') }}</dt><dd>{{ $m['reports']['failed'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.duration') }}</dt><dd>{{ $num($m['reports']['avg_ms'], ' ms') }} / {{ $num($m['reports']['p95_ms'], ' ms') }}</dd>
                    <dt>{{ __('ui.dashboard.health.pdf_failed') }}</dt><dd>{{ $m['reports']['pdf_failed'] }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section :heading="__('ui.dashboard.health.reminders')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>{{ __('ui.dashboard.health.sent') }}</dt><dd>{{ $m['reminders']['sent'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.acted') }}</dt><dd>{{ $m['reminders']['acknowledged'] }} ({{ $pct($m['reminders']['conversion']) }})</dd>
                    <dt>{{ __('ui.dashboard.health.dismissed') }}</dt><dd>{{ $m['reminders']['dismissed'] }}</dd>
                    <dt>{{ __('ui.dashboard.health.snoozes') }}</dt><dd>{{ $m['reminders']['snoozes'] }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section :heading="__('ui.dashboard.health.system')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>{{ __('ui.dashboard.health.failed_jobs') }}</dt><dd>{{ $m['queue']['failed_total'] }} ({{ $m['queue']['failed_period'] }})</dd>
                    <dt>{{ __('ui.dashboard.health.backlog') }}</dt><dd>@foreach ($m['queue']['backlog'] as $q => $n){{ $q }}: {{ $n }}@if (! $loop->last) · @endif @endforeach</dd>
                    <dt>{{ __('ui.dashboard.health.telegram_failures') }}</dt><dd>{{ $m['telegram_failures'] }}</dd>
                </dl>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('ui.dashboard.health.costs')">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th>{{ __('ui.dashboard.health.purpose') }}</th><th>{{ __('ui.dashboard.health.requests') }}</th><th>{{ __('ui.dashboard.health.failed') }}</th><th>{{ __('ui.dashboard.health.tokens') }}</th></tr></thead>
                <tbody>
                    @forelse ($m['costs']['by_purpose'] as $row)
                        <tr><td>{{ $row['purpose'] }}</td><td>{{ $row['requests'] }}</td><td>{{ $row['failed'] }}</td><td>{{ number_format($row['tokens_input']) }} / {{ number_format($row['tokens_output']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-gray-500">{{ __('ui.dashboard.health.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($m['costs']['by_project'] !== [])
                <table class="mt-4 w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th>{{ __('ui.dashboard.reports.columns.project') }}</th><th>{{ __('ui.dashboard.health.tokens') }}</th></tr></thead>
                    <tbody>@foreach ($m['costs']['by_project'] as $row)<tr><td>{{ $row['project'] }}</td><td>{{ number_format($row['tokens_input']) }} / {{ number_format($row['tokens_output']) }}</td></tr>@endforeach</tbody>
                </table>
            @endif
            <p class="mt-3 text-xs text-gray-500">
                @if ($m['costs']['estimate'] !== null) {{ __('ui.dashboard.health.estimate', ['amount' => $m['costs']['estimate']]) }} @else {{ __('ui.dashboard.health.no_pricing') }} @endif
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
