<?php

use App\Services\Anthropic\AnthropicKeyVerifier;
use App\Models\LlmUsage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Per-user Anthropic API key (stored encrypted), used by Card Import to read screenshots. */
new #[Title('AI settings')] class extends Component {
    /** Only holds a newly typed key until it's saved — the stored key is never sent to the browser. */
    public string $apiKey = '';

    public bool $configured = false;

    public function mount(): void
    {
        $this->configured = Auth::user()->hasAnthropicApiKey();
    }

    public function saveKey(): void
    {
        $this->validate(['apiKey' => ['required', 'string', 'max:255']]);

        if ($error = app(AnthropicKeyVerifier::class)->verify(trim($this->apiKey))) {
            $this->addError('apiKey', $error);

            return;
        }

        Auth::user()->setAnthropicApiKey($this->apiKey);
        $this->apiKey = '';
        $this->configured = true;

        Flux::toast(variant: 'success', text: __('Anthropic API key saved.'));
    }

    /** @return array{month: array{calls: int, cost: int}, total: array{calls: int, cost: int}} */
    #[Computed]
    public function totals(): array
    {
        $sum = fn ($query): array => ['calls' => (int) $query->count(), 'cost' => (int) $query->sum('cost_micros')];

        return [
            'month' => $sum(Auth::user()->llmUsages()->where('created_at', '>=', now()->startOfMonth())),
            'total' => $sum(Auth::user()->llmUsages()),
        ];
    }

    /** @return Collection<int, LlmUsage> */
    #[Computed]
    public function recent(): Collection
    {
        return Auth::user()->llmUsages()->latest('id')->limit(10)->get();
    }

    public function removeKey(): void
    {
        Auth::user()->setAnthropicApiKey(null);
        $this->configured = false;

        Flux::toast(text: __('Anthropic API key removed.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('AI settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('AI')" :subheading="__('Claude is used to read transactions from screenshots')">
        <form wire:submit="saveKey" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Anthropic API key') }}</flux:heading>
                <flux:text>
                    {{ __('Create one at console.anthropic.com → API keys. It is stored encrypted and never shown again. Usage is billed to that key.') }}
                </flux:text>
            </div>

            @if ($configured)
                <flux:callout variant="success" icon="check-circle" :heading="__('An Anthropic API key is saved for your account.')" />
            @else
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No Anthropic API key set')" />
            @endif

            <flux:input
                wire:model="apiKey"
                type="password"
                viewable
                autocomplete="off"
                :label="$configured ? __('Replace key') : __('API key')"
                data-test="anthropic-key-input"
            />

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save key') }}</flux:button>
                @if ($configured)
                    <flux:button variant="danger" wire:click="removeKey" wire:confirm="{{ __('Remove your Anthropic API key?') }}">{{ __('Remove key') }}</flux:button>
                @endif
            </div>
        </form>

        {{-- Usage (estimated from Anthropic list prices, see App\Services\Anthropic\Pricing) --}}
        <div class="mt-10 flex flex-col gap-4" data-test="ai-usage">
            <div>
                <flux:heading size="lg">{{ __('Usage') }}</flux:heading>
                <flux:text>{{ __('Estimated from token counts and list prices. Your Anthropic console shows the exact bill.') }}</flux:text>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach (['month' => __('This month'), 'total' => __('All time')] as $key => $label)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm">{{ $label }}</flux:text>
                        <div class="text-xl font-semibold">{{ LlmUsage::formatCost($this->totals[$key]['cost']) }}</div>
                        <flux:text class="text-xs">{{ trans_choice('{1} 1 call|[0,*] :count calls', $this->totals[$key]['calls'], ['count' => $this->totals[$key]['calls']]) }}</flux:text>
                    </div>
                @endforeach
            </div>

            @if ($this->recent->isNotEmpty())
                <div class="flex flex-col text-sm">
                    @foreach ($this->recent as $usage)
                        <div wire:key="usage-{{ $usage->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-200 py-1.5 dark:border-zinc-700">
                            <span class="text-zinc-500">{{ $usage->created_at?->format('Y-m-d H:i') }}</span>
                            <span class="truncate">{{ $usage->model }}</span>
                            <span class="text-zinc-500">{{ number_format($usage->input_tokens) }} / {{ number_format($usage->output_tokens) }}</span>
                            <span class="font-medium">{{ LlmUsage::formatCost($usage->cost_micros) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
