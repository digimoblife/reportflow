<x-filament-panels::page>
    <div class="space-y-6">
        @if ($notice)
            <p class="text-sm {{ $noticeKind === 'success' ? 'text-success-600' : 'text-warning-600' }}">{{ $notice }}</p>
        @endif

        <x-filament::section :heading="__('ui.dashboard.settings.profile')">
            <form wire:submit="saveProfile" class="space-y-4">
                <label class="block text-sm">
                    <span class="font-medium">{{ __('ui.dashboard.settings.language') }}</span>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select wire:model="language">
                            @foreach (['id', 'en'] as $code)
                                <option value="{{ $code }}">{{ __('ui.dashboard.settings.languages.'.$code) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <label class="block text-sm">
                    <span class="font-medium">{{ __('ui.dashboard.settings.timezone') }}</span>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select wire:model="timezone">
                            @foreach ($this->timezones() as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <fieldset class="text-sm">
                    <legend class="font-medium">{{ __('ui.dashboard.settings.workdays') }}</legend>
                    <div class="mt-1 flex flex-wrap gap-4">
                        @foreach (\App\Filament\Pages\Settings::DAYS as $day)
                            <label class="flex items-center gap-1"><input type="checkbox" wire:model="workdays" value="{{ $day }}"> {{ __('ui.dashboard.settings.days.'.$day) }}</label>
                        @endforeach
                    </div>
                </fieldset>

                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="remindersEnabled"> {{ __('ui.dashboard.settings.reminders') }}</label>

                <x-filament::button type="submit">{{ __('ui.dashboard.settings.save') }}</x-filament::button>
            </form>
        </x-filament::section>

        <x-filament::section :heading="__('ui.dashboard.settings.projects')">
            <div class="space-y-4">
                @foreach ($this->projects as $project)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="project-{{ $project['id'] }}">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="font-medium">{{ __('ui.dashboard.settings.project_name') }}@unless ($project['active']) <span class="text-xs text-gray-500">({{ __('ui.dashboard.settings.archived') }})</span>@endunless</span>
                                <x-filament::input.wrapper class="mt-1"><x-filament::input type="text" wire:model="names.{{ $project['id'] }}" maxlength="80" /></x-filament::input.wrapper>
                            </label>
                            <label class="block text-sm">
                                <span class="font-medium">{{ __('ui.dashboard.settings.aliases') }}</span>
                                <x-filament::input.wrapper class="mt-1"><x-filament::input type="text" wire:model="aliases.{{ $project['id'] }}" /></x-filament::input.wrapper>
                            </label>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <x-filament::button size="xs" wire:click="saveProject({{ $project['id'] }})">{{ __('ui.dashboard.settings.save') }}</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="setProjectActive({{ $project['id'] }}, {{ $project['active'] ? 'false' : 'true' }})">
                                {{ $project['active'] ? __('ui.dashboard.settings.archive') : __('ui.dashboard.settings.restore') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach

                <form wire:submit="addProject" class="flex items-end gap-2">
                    <label class="block flex-1 text-sm">
                        <span class="font-medium">{{ __('ui.dashboard.settings.project_name') }}</span>
                        <x-filament::input.wrapper class="mt-1"><x-filament::input type="text" wire:model="newProject" maxlength="80" /></x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit">{{ __('ui.dashboard.settings.add_project') }}</x-filament::button>
                </form>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
