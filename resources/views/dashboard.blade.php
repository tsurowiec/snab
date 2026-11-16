<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">snab</flux:heading>
            <flux:text>{{ __('Suro needs a budget — pick a tool.') }}</flux:text>
        </div>

        {{-- One card per tool (see AGENTS.md → "Adding a tool") --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <a href="{{ route('conversions.index') }}" wire:navigate
               class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-accent hover:ring-3 hover:ring-accent/10 dark:border-zinc-700 dark:bg-zinc-900"
               data-test="tool-conversions">
                <flux:icon.arrows-right-left class="text-accent" />
                <flux:heading size="lg">{{ __('Conversions') }}</flux:heading>
                <flux:text>{{ __('Convert foreign-currency YNAB transactions to PLN at the NBP rate of each transaction date.') }}</flux:text>
            </a>
        </div>
    </div>
</x-layouts::app>
