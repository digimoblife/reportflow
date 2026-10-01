<x-filament-panels::page>
    <div wire:poll.5s class="space-y-6">
        <x-filament::section>
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.worklog.intro') }}</p>

            <form wire:submit="submit" class="space-y-3">
                <x-filament::input.wrapper>
                    <textarea wire:model="text" rows="4" maxlength="8000" required
                              placeholder="{{ __('ui.dashboard.worklog.placeholder') }}"
                              class="block w-full border-none bg-transparent px-3 py-1.5 text-base text-gray-950 outline-none dark:text-white sm:text-sm"></textarea>
                </x-filament::input.wrapper>

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">
                        {{ __('ui.dashboard.worklog.submit') }}
                    </x-filament::button>

                    @if ($notice)
                        <span class="text-sm {{ $noticeKind === 'success' ? 'text-success-600' : 'text-warning-600' }}">{{ $notice }}</span>
                    @endif
                </div>
            </form>
        </x-filament::section>

        <x-filament::section :heading="__('ui.dashboard.worklog.recent')">
            @forelse ($this->entries as $entry)
                <div class="border-b border-gray-200 py-4 last:border-b-0 dark:border-white/10" wire:key="entry-{{ $entry['id'] }}">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <x-filament::badge size="sm">{{ __('ui.dashboard.worklog.channel.'.$entry['source']) }}</x-filament::badge>
                        <x-filament::badge size="sm" :color="match ($entry['status']) { 'processed' => 'success', 'failed' => 'danger', 'needs_clarification' => 'warning', default => 'gray' }">
                            {{ __('ui.dashboard.worklog.states.'.$entry['status']) }}
                        </x-filament::badge>
                        <span>{{ $entry['at'] }}</span>
                    </div>

                    <p class="mt-2 whitespace-pre-line text-sm text-gray-950 dark:text-white">{{ \Illuminate\Support\Str::limit($entry['text'], 400) }}</p>

                    @foreach ($entry['items'] as $item)
                        <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5" wire:key="item-{{ $entry['id'] }}-{{ $item['index'] }}">
                            @if ($item['applied'])
                                @php($v = $item['view'])
                                <div class="font-medium">📁 {{ $v['project'] ?? '-' }} → {{ $v['task'] ?? '-' }}@if ($v['is_new']) <span class="text-xs text-gray-500">({{ __('ui.labels.new') }})</span>@endif</div>
                                @if ($v['activity'])
                                    <div>🛠 {{ __('ui.labels.activity') }}: {{ __('ui.activity_types.'.$v['activity']['type']) }} — {{ $v['activity']['summary'] }}</div>
                                @endif
                                @if ($v['status_to'])
                                    <div>🔄 {{ __('ui.labels.status') }}:
                                        @if ($v['status_changed'] && $v['status_from']) {{ __('ui.statuses.'.$v['status_from']) }} → @endif{{ __('ui.statuses.'.$v['status_to']) }}@if ($v['reopened']) ({{ __('ui.labels.reopened') }})@endif
                                    </div>
                                @endif
                                @if ($v['date_differs'] && $v['activity'])
                                    <div>📅 {{ $v['activity']['date'] }}</div>
                                @endif

                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-filament::button size="xs" color="gray" wire:click="undo({{ $entry['id'] }}, {{ $item['index'] }})">{{ __('ui.dashboard.worklog.actions.undo') }}</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="openPanel('move', {{ $entry['id'] }}, {{ $item['index'] }})">{{ __('ui.dashboard.worklog.actions.move') }}</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="openPanel('status', {{ $entry['id'] }}, {{ $item['index'] }})">{{ __('ui.dashboard.worklog.actions.status') }}</x-filament::button>
                                    @if ($item['can_change_project'])
                                        <x-filament::button size="xs" color="gray" wire:click="openPanel('project', {{ $entry['id'] }}, {{ $item['index'] }})">{{ __('ui.dashboard.worklog.actions.project') }}</x-filament::button>
                                    @endif
                                </div>

                                @if ($panelKind && $panelMessage === $entry['id'] && $panelItem === $item['index'])
                                    <div class="mt-3 space-y-2 border-t border-gray-200 pt-3 dark:border-white/10">
                                        <div>{{ __('ui.dashboard.worklog.pick.'.$panelKind, ['task' => $this->panelTaskTitle]) }}</div>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="choice">
                                                <option value="">{{ __('ui.dashboard.worklog.pick.choose') }}</option>
                                                @foreach ($this->choices as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        <div class="flex gap-2">
                                            <x-filament::button size="xs" wire:click="applyPanel">{{ __('ui.dashboard.worklog.actions.apply') }}</x-filament::button>
                                            <x-filament::button size="xs" color="gray" wire:click="closePanel">{{ __('ui.dashboard.worklog.actions.cancel') }}</x-filament::button>
                                        </div>
                                    </div>
                                @endif
                            @else
                                <span class="text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.worklog.item_states.'.$item['state']) }}</span>
                            @endif
                        </div>
                    @endforeach

                    @if ($entry['applied_count'] > 1)
                        <div class="mt-2">
                            <x-filament::button size="xs" color="gray" wire:click="undo({{ $entry['id'] }})">{{ __('ui.dashboard.worklog.actions.undo_all') }}</x-filament::button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('ui.dashboard.worklog.empty') }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
