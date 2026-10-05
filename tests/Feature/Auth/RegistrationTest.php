<?php

namespace Tests\Feature\Auth;

use Livewire\Volt\Volt;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.register');
});

test('new users can register', function () {
    seedRoles();
    $component = Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password');

    $component->call('register');

    $component->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('investor registration assigns the role, creates a profile and a wallet', function () {
    seedRoles();
    Volt::test('pages.auth.register')
        ->set('name', 'Ina Investor')->set('email', 'ina@example.com')
        ->set('password', 'password')->set('password_confirmation', 'password')
        ->call('register')->assertHasNoErrors();

    $user = \App\Models\User::firstWhere('email', 'ina@example.com');
    expect($user->hasRole('INVESTOR'))->toBeTrue()->and($user->investor)->not->toBeNull()
        ->and($user->wallets()->count())->toBe(1)
        ->and($user->investor->kyc_status->value)->toBe('NOT_SUBMITTED');
});

test('business registration requires a business name and creates a business profile', function () {
    seedRoles();
    Volt::test('pages.auth.register')
        ->set('account_type', 'BUSINESS')->set('name', 'Bob')->set('email', 'bob@example.com')
        ->set('password', 'password')->set('password_confirmation', 'password')
        ->call('register')->assertHasErrors(['business_name']);

    Volt::test('pages.auth.register')
        ->set('account_type', 'BUSINESS')->set('business_name', 'Bob Traders')->set('name', 'Bob')->set('email', 'bob@example.com')
        ->set('password', 'password')->set('password_confirmation', 'password')
        ->call('register')->assertHasNoErrors();

    expect(\App\Models\User::firstWhere('email', 'bob@example.com')->business->name)->toBe('Bob Traders');
});

test('nobody can self-register as admin', function () {
    seedRoles();
    Volt::test('pages.auth.register')
        ->set('account_type', 'ADMIN')->set('name', 'Eve')->set('email', 'eve@example.com')
        ->set('password', 'password')->set('password_confirmation', 'password')
        ->call('register')->assertHasErrors(['account_type']);
    expect(\App\Models\User::where('email', 'eve@example.com')->exists())->toBeFalse();
});
