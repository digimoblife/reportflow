<x-filament-panels::page.simple>
    @if ($this->botUsername())
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.login.intro') }}</p>
        <div class="flex justify-center py-2">
            <script async src="https://telegram.org/js/telegram-widget.js?22"
                    data-telegram-login="{{ $this->botUsername() }}"
                    data-size="large"
                    data-auth-url="{{ route('auth.telegram.callback') }}"
                    data-request-access="write"></script>
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('ui.dashboard.login.not_configured') }}</p>
    @endif
</x-filament-panels::page.simple>
