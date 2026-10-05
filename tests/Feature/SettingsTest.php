<?php

use App\Livewire\Admin\Settings;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Livewire\Livewire;

function adminUser(string $role = 'ADMIN'): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

it('falls back to defaults and lets an admin change limits, with an audit trail', function () {
    $admin = adminUser();
    expect(app(SettingsService::class)->minor('finance.min_withdrawal'))->toBe(100000);

    Livewire::actingAs($admin)->test(Settings::class)->set('values.finance.min_withdrawal', '2500')->call('save')->assertHasNoErrors()->assertSee('Settings saved');
    expect(app(SettingsService::class)->minor('finance.min_withdrawal'))->toBe(250000);
    $log = AuditLog::where('action', 'settings.updated')->first();
    expect($log->old_values['finance.min_withdrawal'])->toBe('1000')->and($log->new_values['finance.min_withdrawal'])->toBe('2500');
});

it('changed limits are enforced by the financial services', function () {
    $admin = adminUser();
    Livewire::actingAs($admin)->test(Settings::class)->set('values.finance.min_withdrawal', '5000')->call('save');
    $inv = makeInvestor(10000000);
    expect(fn () => app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(300000), 'lim'))->toThrow(\App\Exceptions\FinancialException::class, 'minimum withdrawal is BDT 5,000.00');
});

it('validates settings and rejects min above max', function () {
    $admin = adminUser();
    Livewire::actingAs($admin)->test(Settings::class)->set('values.finance.min_withdrawal', '900000')->call('save')->assertHasErrors('values.finance.min_withdrawal');
    Livewire::actingAs($admin)->test(Settings::class)->set('values.platform.contact_email', 'not-an-email')->call('save')->assertHasErrors('values.platform.contact_email');
});

it('only users with settings.manage can open or save settings', function () {
    $this->actingAs(adminUser('MANAGER'))->get('/admin/settings')->assertForbidden();
    $this->actingAs(adminUser('STAFF'))->get('/admin/settings')->assertForbidden();
    Livewire::actingAs(adminUser('MANAGER'))->test(Settings::class)->assertForbidden();
    $this->actingAs(adminUser('ADMIN'))->get('/admin/settings')->assertOk()->assertSee('Minimum investment');
});
