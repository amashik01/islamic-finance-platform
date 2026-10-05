<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password');

    $component->call('login');

    $component
        ->assertHasErrors()
        ->assertNoRedirect();

    $this->assertGuest();
});

test('each role lands in its own portal and the sidebar renders', function () {
    $this->actingAs(makeInvestor()->user);
    $this->get('/dashboard')->assertRedirect(route('investor.dashboard'));
    $this->get('/investor')->assertOk()->assertSee('Available Balance')->assertSee('My Wallet');

    $this->actingAs(makeBusiness()->user);
    $this->get('/dashboard')->assertRedirect(route('business.dashboard'));
    $this->get('/business')->assertOk()->assertSee('Active Projects');

    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');
    $this->actingAs($admin);
    $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    $this->get('/admin/dashboard')->assertOk()->assertSee('COMMAND CENTER');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('layout.navigation');

    $component->call('logout');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
});
