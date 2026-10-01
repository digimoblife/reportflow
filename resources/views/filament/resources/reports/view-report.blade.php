<x-filament-panels::page>
    @php($report = $this->report())
    @php($version = $this->loadedVersion())
    @php($tz = $this->timezone())

    <div wire:poll.3s="refreshState" class="space-y-6">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <x-filament::badge :color="match ($report->status->value) { 'approved' => 'success', 'generating' => 'info', 'in_review' => 'warning', default => 'gray' }">
                {{ __('ui.dashboard.reports.statuses.'.$report->status->value) }}
            </x-filament::badge>
            @if ($version)
                <span class="text-gray-500">v{{ $version->version_no }} · {{ __('ui.dashboard.reports.view.snapshot', ['at' => $version->data_snapshot_at->copy()->setTimezone($tz)->format('d M Y H:i')]) }}</span>
            @endif
        </div>

        @if ($this->isGenerating())
            <p class="text-sm text-info-600">{{ __('ui.dashboard.reports.view.generating') }}</p>
        @endif

        @if ($newerAvailable)
            <div class="flex items-center gap-3 text-sm text-warning-600">
                <span>{{ __('ui.dashboard.reports.view.newer') }}</span>
                <x-filament::button size="xs" color="gray" wire:click="loadNewest">{{ __('ui.dashboard.reports.view.load_newer') }}</x-filament::button>
            </div>
        @endif

        @if ($version)
            <x-filament::section :heading="__('ui.dashboard.reports.view.preview')">
                <iframe class="h-[70vh] w-full rounded border border-gray-200 bg-white dark:border-white/10" sandbox srcdoc="{{ $this->previewHtml() }}" title="{{ __('ui.dashboard.reports.view.preview') }}"></iframe>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ __('ui.dashboard.reports.view.downloads') }}:</span>
                    @php($links = $this->downloads())
                    @if ($links === [])
                        <span class="text-gray-500">{{ __('ui.dashboard.reports.view.files_pending') }}</span>
                    @else
                        <x-filament::button size="xs" tag="a" :href="$links['pdf']">{{ __('ui.dashboard.reports.view.pdf') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" tag="a" :href="$links['md']">{{ __('ui.dashboard.reports.view.markdown') }}</x-filament::button>
                    @endif
                </div>
            </x-filament::section>

            @if ($this->isEditable())
                <x-filament::section :heading="__('ui.dashboard.reports.view.editor')">
                    @if ($report->status->value === 'approved')
                        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.reports.view.approved_note') }}</p>
                    @endif

                    <div class="space-y-4">
                        @foreach ($version->content['sections'] as $section)
                            <label class="block text-sm" wire:key="sec-{{ $section['key'] }}">
                                <span class="font-medium">{{ $section['title'] }}</span>
                                @if ($section['fallback'] ?? false)
                                    <span class="ml-2 text-xs text-warning-600">{{ __('ui.dashboard.reports.view.fallback') }}</span>
                                @endif
                                <x-filament::input.wrapper class="mt-1">
                                    <textarea wire:model="sections.{{ $section['key'] }}" rows="{{ max(4, min(16, substr_count($sections[$section['key']] ?? '', "\n") + 2)) }}"
                                              class="block w-full border-none bg-transparent px-3 py-1.5 font-mono text-sm text-gray-950 outline-none dark:text-white"></textarea>
                                </x-filament::input.wrapper>
                            </label>

                            <div class="flex flex-wrap items-center gap-2" wire:key="instr-{{ $section['key'] }}">
                                <x-filament::input.wrapper class="min-w-[16rem] flex-1">
                                    <x-filament::input type="text" wire:model="instructions.{{ $section['key'] }}" maxlength="1000"
                                        placeholder="{{ __('ui.dashboard.reports.view.instruct_placeholder') }}" aria-label="{{ __('ui.dashboard.reports.view.instruct_label') }}" />
                                </x-filament::input.wrapper>
                                <x-filament::button size="xs" color="gray" wire:click="instruct('{{ $section['key'] }}')">{{ __('ui.dashboard.reports.view.instruct_button') }}</x-filament::button>
                            </div>

                            @if (in_array($section['key'], $factOffers, true))
                                <div class="rounded-lg bg-warning-50 p-3 text-sm dark:bg-white/5" wire:key="offer-{{ $section['key'] }}">
                                    <p>{{ __('ui.dashboard.reports.view.fact_offer', ['section' => $section['title']]) }}</p>
                                    @if ($factSection === $section['key'])
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                            <x-filament::input.wrapper><x-filament::input.select wire:model="factTask">
                                                <option value="">{{ __('ui.dashboard.reports.view.fact_task') }}…</option>
                                                @foreach ($this->reportTasks() as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
                                            </x-filament::input.select></x-filament::input.wrapper>
                                            <x-filament::input.wrapper><x-filament::input type="date" wire:model="factDate" /></x-filament::input.wrapper>
                                            <x-filament::input.wrapper class="sm:col-span-2"><x-filament::input type="text" wire:model="factSummary" maxlength="300" placeholder="{{ __('ui.dashboard.reports.view.fact_summary') }}" /></x-filament::input.wrapper>
                                        </div>
                                        <div class="mt-2 flex gap-2">
                                            <x-filament::button size="xs" wire:click="saveFact">{{ __('ui.dashboard.reports.view.fact_save') }}</x-filament::button>
                                            <x-filament::button size="xs" color="gray" wire:click="dismissFact('{{ $section['key'] }}')">{{ __('ui.dashboard.reports.view.fact_dismiss') }}</x-filament::button>
                                        </div>
                                    @else
                                        <div class="mt-2 flex gap-2">
                                            <x-filament::button size="xs" wire:click="openFact('{{ $section['key'] }}')">{{ __('ui.dashboard.reports.view.fact_save') }}</x-filament::button>
                                            <x-filament::button size="xs" color="gray" wire:click="dismissFact('{{ $section['key'] }}')">{{ __('ui.dashboard.reports.view.fact_dismiss') }}</x-filament::button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-filament::button wire:click="saveSections">{{ __('ui.dashboard.reports.view.save') }}</x-filament::button>
                        @if ($report->status->value === 'in_review')
                            <x-filament::button color="success" wire:click="approve" wire:confirm="{{ __('ui.dashboard.reports.view.approve') }}?">{{ __('ui.dashboard.reports.view.approve') }}</x-filament::button>
                        @endif
                        @if ($report->status->value !== 'approved')
                            <x-filament::button color="danger" outlined wire:click="cancelReport" wire:confirm="{{ __('ui.dashboard.reports.view.cancel_report') }}?">{{ __('ui.dashboard.reports.view.cancel_report') }}</x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section :heading="__('ui.dashboard.reports.view.history')">
                <div class="space-y-1 text-sm">
                    @foreach ($this->versions() as $v)
                        <div class="flex flex-wrap items-center gap-2" wire:key="ver-{{ $v->id }}">
                            <span class="font-medium">v{{ $v->version_no }}</span>
                            <span class="text-gray-500">{{ $v->created_at->copy()->setTimezone($tz)->format('d M Y H:i') }}</span>
                            <span>{{ __('ui.dashboard.reports.view.by.'.$v->created_by->value) }}</span>
                            @if ($v->approved_at)
                                <x-filament::badge size="sm" color="success">{{ __('ui.dashboard.reports.statuses.approved') }}</x-filament::badge>
                            @endif
                            @if ($v->instruction)
                                <span class="text-gray-500">“{{ \Illuminate\Support\Str::limit($v->instruction, 80) }}”</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap items-end gap-3 text-sm">
                    <label>
                        <span class="block font-medium">{{ __('ui.dashboard.reports.view.compare_from') }}</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="compareFrom">
                            @foreach ($this->versions() as $v)<option value="{{ $v->id }}">v{{ $v->version_no }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                    <label>
                        <span class="block font-medium">{{ __('ui.dashboard.reports.view.compare_to') }}</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="compareTo">
                            @foreach ($this->versions() as $v)<option value="{{ $v->id }}">v{{ $v->version_no }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                </div>

                @php($diff = $this->diff())
                @if ($diff !== [])
                    <div class="mt-4 space-y-3 text-sm">
                        @foreach ($diff as $d)
                            <div wire:key="diff-{{ $d['key'] }}">
                                <div class="font-medium">{{ $d['title'] }} <span class="text-xs text-gray-500">({{ __('ui.dashboard.reports.view.diff.'.$d['state']) }})</span></div>
                                @if ($d['state'] === 'same')
                                    <p class="text-gray-500">{{ __('ui.dashboard.reports.view.no_change') }}</p>
                                @else
                                    <pre class="overflow-x-auto rounded bg-gray-50 p-2 text-xs dark:bg-white/5">@foreach ($d['lines'] as $line)<span class="{{ $line['type'] === 'add' ? 'text-success-600' : ($line['type'] === 'del' ? 'text-danger-600 line-through' : 'text-gray-500') }}">{{ $line['type'] === 'add' ? '+ ' : ($line['type'] === 'del' ? '- ' : '  ') }}{{ $line['text'] }}</span>
@endforeach</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
