<x-filament-panels::page>
    <div wire:poll.5s class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.inbox.intro') }}</p>

        @if ($notice)
            <p class="text-sm {{ $noticeKind === 'success' ? 'text-success-600' : 'text-warning-600' }}">{{ $notice }}</p>
        @endif

        @forelse ($this->entries as $entry)
            <x-filament::section wire:key="inbox-{{ $entry['id'] }}">
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <x-filament::badge size="sm">{{ __('ui.dashboard.worklog.channel.'.$entry['source']) }}</x-filament::badge>
                    <x-filament::badge size="sm" :color="$entry['status'] === 'failed' ? 'danger' : 'warning'">{{ __('ui.dashboard.worklog.states.'.$entry['status']) }}</x-filament::badge>
                    <span>{{ $entry['at'] }}</span>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm">{{ \Illuminate\Support\Str::limit($entry['text'], 400) }}</p>

                @if ($entry['status'] === 'failed')
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.inbox.failed') }}</p>
                @endif

                @foreach ($entry['questions'] as $question)
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5" wire:key="q-{{ $entry['id'] }}-{{ $question['index'] }}">
                        <div class="whitespace-pre-line">{{ $question['text'] }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($question['buttons'] as $button)
                                <x-filament::button size="xs" color="gray"
                                    wire:click="answer({{ $entry['id'] }}, {{ $question['index'] }}, '{{ $button['action'] }}', '{{ $button['arg'] }}')">{{ $button['label'] }}</x-filament::button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="mt-3">
                    <x-filament::button size="xs" wire:click="reprocess({{ $entry['id'] }})">{{ __('ui.dashboard.inbox.reprocess') }}</x-filament::button>
                </div>
            </x-filament::section>
        @empty
            <p class="text-sm text-gray-500">{{ __('ui.dashboard.inbox.empty') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
