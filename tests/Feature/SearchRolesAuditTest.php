<?php

use App\Livewire\Admin\GlobalSearch;
use App\Livewire\Admin\UsersTable;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Project\ProjectBuilder;
use Livewire\Livewire;

function staffWith(string $role): User
{
    seedRoles();
    $u = User::factory()->create(['name' => 'Searchable Person']);
    $u->assignRole($role);

    return $u;
}

it('global search finds records the staff member is allowed to see', function () {
    $admin = staffWith('ADMIN');
    makeProject(['title' => 'Unique Mill Project']);
    Livewire::actingAs($admin)->test(GlobalSearch::class)->set('q', 'Unique Mill')->assertSee('Unique Mill Project')->assertSee('Projects');
    Livewire::actingAs($admin)->test(GlobalSearch::class)->set('q', 'Searchable')->assertSee('Searchable Person');
    Livewire::actingAs($admin)->test(GlobalSearch::class)->set('q', 'x')->assertDontSee('Unique Mill Project');   // too short
});

it('global search hides ledger references from staff without ledger access', function () {
    $staff = staffWith('STAFF');
    makeInvestor(1000000);
    $ref = \App\Models\Transaction::first()->reference;
    Livewire::actingAs($staff)->test(GlobalSearch::class)->set('q', substr($ref, 0, 8))->assertDontSee($ref);
    Livewire::actingAs(staffWith('ADMIN'))->test(GlobalSearch::class)->set('q', substr($ref, 0, 8))->assertSee($ref);
});

it('shows a pending-actions indicator based on permissions', function () {
    $admin = staffWith('ADMIN');
    $inv = makeInvestor(10000000);
    app(\App\Services\Wallet\WalletService::class)->requestWithdrawal($inv->user, \App\Support\Money\Money::minor(200000), 'pend');
    Livewire::actingAs($admin)->test(GlobalSearch::class)->assertSee('1 pending');
});

it('only admins can change staff roles, never their own, and it is audited', function () {
    $admin = staffWith('ADMIN');
    $target = staffWith('STAFF');
    Livewire::actingAs($admin)->test(UsersTable::class)->call('ask', 'role:MANAGER', $target->id, 'Change role', true)->set('reason', 'Promoted')->call('confirm')->assertSet('error', null);
    expect($target->fresh()->hasRole('MANAGER'))->toBeTrue()->and($target->fresh()->hasRole('STAFF'))->toBeFalse();
    $log = AuditLog::where('action', 'role.changed')->first();
    expect($log->old_values['roles'])->toBe(['STAFF'])->and($log->new_values['roles'])->toBe(['MANAGER'])->and($log->reason)->toBe('Promoted');

    Livewire::actingAs($admin)->test(UsersTable::class)->call('ask', 'role:STAFF', $admin->id, 'x', true)->set('reason', 'y')->call('confirm')->assertSet('error', 'You cannot change your own role.');
    $investor = makeInvestor()->user;
    Livewire::actingAs($admin)->test(UsersTable::class)->call('ask', 'role:ADMIN', $investor->id, 'x', true)->set('reason', 'y')->call('confirm')->assertSet('error', 'Only staff roles can be changed here.');
    expect($investor->fresh()->hasRole('ADMIN'))->toBeFalse();

    Livewire::actingAs(staffWith('MANAGER'))->test(UsersTable::class)->call('ask', 'role:ADMIN', $target->id, 'x', true)->set('reason', 'y')->call('confirm')->assertSet('error', 'You are not allowed to do that.');
});

it('audits ratio changes when draft contract terms are edited', function () {
    $b = makeBusiness();
    $svc = app(ProjectBuilder::class);
    $d = ['title' => 'Ratio Test', 'description' => 'Desc', 'industry' => 'X', 'purpose' => 'Y', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'minimum_amount' => '5000', 'contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30'];
    $p = $svc->saveDraft($b, $d);
    $svc->saveDraft($b, ['investor_profit' => '60', 'business_profit' => '40'] + $d, $p);
    $log = AuditLog::where('action', 'contract.terms_modified')->first();
    expect($log)->not->toBeNull()->and($log->old_values['investor_profit_bps'])->toBe(7000)->and($log->new_values['investor_profit_bps'])->toBe(6000);
});
