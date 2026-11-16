<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Anthropic\AnthropicKeyVerifier;
use Livewire\Livewire;

function fakeVerifier(?string $error): void
{
    app()->instance(AnthropicKeyVerifier::class, new class($error) extends AnthropicKeyVerifier
    {
        public function __construct(private ?string $error) {}

        public function verify(string $apiKey): ?string
        {
            return $this->error;
        }
    });
}

it('saves a verified key encrypted', function () {
    fakeVerifier(null);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.ai')
        ->set('apiKey', ' sk-ant-secret ')
        ->call('saveKey')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertSet('configured', true)
        ->assertDontSee('sk-ant-secret');

    expect($user->anthropicApiKey())->toBe('sk-ant-secret')
        ->and($user->setting(Setting::ANTHROPIC_API_KEY))->not->toContain('sk-ant-secret');
});

it('does not save a rejected key', function () {
    fakeVerifier('Anthropic rejected this API key.');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.ai')
        ->set('apiKey', 'bad')
        ->call('saveKey')
        ->assertHasErrors('apiKey');

    expect($user->hasAnthropicApiKey())->toBeFalse();
});

it('never renders the stored key and can remove it', function () {
    $user = User::factory()->withAnthropicKey('sk-ant-stored')->create();

    Livewire::actingAs($user)
        ->test('pages::settings.ai')
        ->assertSee('An Anthropic API key is saved')
        ->assertDontSee('sk-ant-stored')
        ->call('removeKey')
        ->assertSet('configured', false);

    expect($user->hasAnthropicApiKey())->toBeFalse();
});
