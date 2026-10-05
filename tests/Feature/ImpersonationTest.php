<?php

use App\Models\AuditLog;
use App\Models\User;

function impersonationAdmin(): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole('ADMIN');

    return $u;
}

it('in local development an admin can log in as an investor, business or staff user and return, all audited', function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    app()->detectEnvironment(fn () => 'local');
    $admin = impersonationAdmin();
    $staff = User::factory()->create();
    $staff->assignRole('STAFF');
    foreach ([makeInvestor()->user, makeBusiness()->user, $staff] as $target) {
        $this->actingAs($admin)->post(route('impersonate.start', $target))->assertRedirect(route($target->homeRoute()));
        expect(auth()->id())->toBe($target->id);
        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.users'));
        expect(auth()->id())->toBe($admin->id);
    }
    expect(AuditLog::where('action', 'impersonation.started')->count())->toBe(3)->and(AuditLog::where('action', 'impersonation.stopped')->count())->toBe(3);
});

it('is not available outside local, to non-admins, or against admins and managers', function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    $admin = impersonationAdmin();
    $target = makeInvestor()->user;
    app()->detectEnvironment(fn () => 'production');
    $this->actingAs($admin)->post(route('impersonate.start', $target))->assertNotFound();
    app()->detectEnvironment(fn () => 'local');
    $this->actingAs(makeInvestor()->user)->post(route('impersonate.start', $target))->assertForbidden();
    $manager = User::factory()->create();
    $manager->assignRole('MANAGER');
    $this->actingAs($admin)->post(route('impersonate.start', $manager))->assertForbidden();
    $this->actingAs($admin)->post(route('impersonate.start', impersonationAdmin()))->assertForbidden();
});
