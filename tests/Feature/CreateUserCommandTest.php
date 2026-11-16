<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a user', function () {
    $this->artisan('snab:user', ['email' => 'Suro@Example.com', '--name' => 'Suro', '--password' => 'secret-password'])
        ->expectsOutputToContain('Created user suro@example.com')
        ->assertSuccessful();

    $user = User::where('email', 'suro@example.com')->sole();
    expect($user->name)->toBe('Suro')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('resets the password of an existing user', function () {
    $user = User::factory()->create(['email' => 'suro@example.com', 'name' => 'Suro']);

    $this->artisan('snab:user', ['email' => 'suro@example.com', '--password' => 'new-password'])
        ->expectsOutputToContain('Updated user')
        ->assertSuccessful();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and(User::count())->toBe(1);
});

it('prompts for name and password', function () {
    $this->artisan('snab:user', ['email' => 'new@example.com'])
        ->expectsQuestion('Name', 'New Person')
        ->expectsQuestion('Password', 'prompted-password')
        ->assertSuccessful();

    expect(User::where('email', 'new@example.com')->value('name'))->toBe('New Person');
});

it('rejects an invalid e-mail', function () {
    $this->artisan('snab:user', ['email' => 'not-an-email', '--name' => 'X', '--password' => 'secret'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});
