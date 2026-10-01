<x-filament-panels::page>
    @php($task = $this->task())
    @php($tz = \App\Filament\Resources\Tasks\TaskResource::timezone())

    <div wire:poll.10s class="space-y-6">
        <x-filament::section :heading="__('ui.dashboard.tasks.view.details')">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">{{ __('ui.dashboard.tasks.columns.project') }}</dt><dd class="font-medium">{{ $task->project->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('ui.dashboard.tasks.columns.status') }}</dt>
                    <dd class="font-medium">{{ __('ui.statuses.'.$task->status->value) }}@if ($task->waiting_reason) ({{ __('ui.lists.waiting_for') }} {{ __('ui.waiting_reasons.'.$task->waiting_reason->value) }})@endif</dd></div>
                <div><dt class="text-gray-500">{{ __('ui.dashboard.tasks.columns.last_activity') }}</dt>
                    <dd class="font-medium">{{ $task->last_activity_at?->copy()->setTimezone($tz)->format('d M Y') ?? '-' }}</dd></div>
                <div class="text-xs text-gray-500">{{ __('ui.dashboard.tasks.view.version_note', ['version' => $this->loadedVersion]) }}</div>
            </dl>
        </x-filament::section>

        @php($timeline = $this->timeline())

        <x-filament::section :heading="__('ui.dashboard.tasks.view.activities')">
            @forelse ($timeline['activities'] as $activity)
                <div class="border-b border-gray-200 py-2 text-sm last:border-b-0 dark:border-white/10" wire:key="act-{{ $activity->id }}">
                    <span class="text-gray-500">{{ $activity->activity_date->format('d M Y') }}</span> —
                    <span class="font-medium">{{ __('ui.activity_types.'.$activity->activity_type->value) }}</span>: {{ $activity->summary }}
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('ui.dashboard.tasks.view.no_activities') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="__('ui.dashboard.tasks.view.events')">
            @forelse ($timeline['events'] as $event)
                <div class="border-b border-gray-200 py-2 text-sm last:border-b-0 dark:border-white/10" wire:key="ev-{{ $event->id }}">
                    <span class="text-gray-500">{{ $event->created_at->copy()->setTimezone($tz)->format('d M Y H:i') }}</span> — {{ $this->eventLabel($event) }}
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('ui.dashboard.tasks.view.no_events') }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
