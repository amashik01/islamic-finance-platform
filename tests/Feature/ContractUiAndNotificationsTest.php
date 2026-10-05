<?php

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\ContractDetails;
use App\Models\User;
use App\Services\Project\ProjectWorkflow;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Livewire\Livewire;

function staffAs(UserRole $role): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role->value);
    test()->actingAs($u);

    return $u;
}

it('previews and posts a Mudarabah settlement from the admin contract page', function () {
    staffAs(UserRole::Admin);
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $inv = makeInvestor(20000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(10000000), 'ui-m');

    closeOut($contract, 2000000);   // capital deployed and returned, profit remitted as interim proceeds
    $c = Livewire::test(ContractDetails::class, ['contract' => $contract->fresh()])
        ->set('netResult', '20000')->assertSee('BDT 14,000.00')->assertSee('BDT 100,000.00')->assertSee('BDT 6,000.00');
    $c->call('settle')->assertSet('error', 'Record a reason or reference for this settlement.');
    $c->set('reason', 'FY result audited')->call('settle')->assertSee('posted');

    $bal = app(WalletService::class)->balances(app(WalletService::class)->walletFor($inv->user));
    expect($bal['available']->minor)->toBe(10000000 + 10000000 + 1400000)->and($contract->fresh()->status)->toBe(ContractStatus::Completed);
});

it('staff cannot settle contracts', function () {
    staffAs(UserRole::Staff);
    $contract = activeContract(makeProject());
    Livewire::test(ContractDetails::class, ['contract' => $contract])->set('netResult', '1000')->set('reason', 'x')->call('settle')->assertForbidden();
});

it('drives a Murabaha contract through every stage and payment from the UI', function () {
    staffAs(UserRole::Manager);
    [$contract] = murabahaContract();
    $c = Livewire::test(ContractDetails::class, ['contract' => $contract])->assertSee('= Sale price')->assertSee('not a loan');

    $c->call('step', 'sale')->assertSet('error', fn ($e) => str_contains($e, 'in possession'));
    $c->call('step', 'verify')->assertSet('error', null)
        ->set('invoice', 'INV-77')->call('step', 'purchase')->assertSet('error', null)
        ->call('step', 'ownership')->assertSet('error', null)
        ->call('step', 'possession')->assertSet('error', 'Describe how possession was taken.')
        ->set('notes', 'Inspected and held')->call('step', 'possession')->assertSet('error', null)
        ->call('step', 'sale')->assertSet('error', null)->assertSee('Receivable and payments');
    $c->set('payAmount', '55000')->call('recordPayment')->assertSet('error', null)
        ->set('payAmount', '55000')->call('recordPayment')->assertSet('error', null);
    expect($contract->fresh()->status)->toBe(ContractStatus::Completed);
});

it('notifies the right people for the key events', function () {
    seedRoles();
    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');
    $inv = makeInvestor(10000000);
    $project = makeProject(['status' => ProjectStatus::Draft]);
    activeContract($project)->forceFill(['status' => ContractStatus::Draft])->save();

    app(InvestmentService::class); // warm
    app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(200000), 'n-w');
    expect($admin->notifications()->where('data->title', 'New withdrawal request')->exists())->toBeTrue();

    $wf = app(ProjectWorkflow::class);
    $wf->submit($project->fresh(), $admin);
    expect($project->business->user->notifications()->where('data->title', 'Project submitted')->exists())->toBeTrue()
        ->and($admin->notifications()->where('data->title', 'New project to review')->exists())->toBeTrue();
    $wf->requestRevision($project->fresh(), $admin, 'Add quotes');
    expect($project->business->user->notifications()->where('data->title', 'Revision requested')->exists())->toBeTrue();

    $open = makeProject();
    app(InvestmentService::class)->invest($inv, $open, Money::minor(1000000), 'n-i');
    expect($inv->user->notifications()->where('data->title', 'Investment confirmed')->exists())->toBeTrue();
});
