<?php

use App\Services\Anthropic\AnthropicKeyVerifier;
use Flux\Flux;
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
    </x-pages::settings.layout>
</section>
