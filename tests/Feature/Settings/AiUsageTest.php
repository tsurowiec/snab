<?php

use App\Models\LlmUsage;
use App\Models\User;
use Livewire\Livewire;

it('shows this month and all-time usage', function () {
    $user = User::factory()->create();
    LlmUsage::factory()->for($user)->create(['cost_micros' => 16000]);
    LlmUsage::factory()->for($user)->create(['cost_micros' => 4000, 'created_at' => now()->subMonths(2)]);
    LlmUsage::factory()->create(['cost_micros' => 999_000]); // another user

    Livewire::actingAs($user)
        ->test('pages::settings.ai')
        ->assertSeeInOrder(['This month', '$0.0160', '1 call', 'All time', '$0.0200', '2 calls'])
        ->assertDontSee('$0.9990');
});
