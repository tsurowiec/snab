<?php

use App\Models\User;

test('home redirects to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('conversions.index'))->assertRedirect(route('login'));
    $this->get(route('ynab.edit'))->assertRedirect(route('login'));
});

test('authenticated users see the tools on the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Conversions')
        ->assertSee(route('conversions.index'));
});

test('registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertNotFound();
});

test('the PWA manifest and icon are served', function () {
    expect(file_exists(public_path('manifest.webmanifest')))->toBeTrue()
        ->and(json_decode(file_get_contents(public_path('manifest.webmanifest')), true))
        ->toMatchArray(['short_name' => 'snab', 'start_url' => '/dashboard', 'display' => 'standalone'])
        ->and(file_exists(public_path('icon.svg')))->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('rel="manifest"', escape: false);
});
