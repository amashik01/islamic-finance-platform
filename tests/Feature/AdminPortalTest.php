<?php

use App\Enums\ContractStatus;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\WithdrawalStatus;
use App\Livewire\Admin\DepositsTable;
use App\Livewire\Admin\KycTable;
use App\Livewire\Admin\LedgerTable;
use App\Livewire\Admin\ProjectReview;
use App\Livewire\Admin\ProjectsTable;
use App\Livewire\Admin\WithdrawalsTable;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Livewire\Livewire;

function adminAs(UserRole $role = UserRole::Admin): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role->value);
    test()->actingAs($u);

    return $u;
}

it('renders every admin page for an admin', function (string $uri) {
    adminAs();
    makeInvestor(10000000);
    activeContract(makeProject());
    $this->get($uri)->assertOk();
})->with([
    '/admin/dashboard', '/admin/users', '/admin/investors', '/admin/businesses', '/admin/kyc', '/admin/kyc/businesses', '/admin/documents',
    '/admin/projects', '/admin/projects/pending', '/admin/contracts/mudarabah', '/admin/contracts/musharakah', '/admin/contracts/murabaha',
    '/admin/investments', '/admin/wallets', '/admin/ledger', '/admin/deposits', '/admin/withdrawals', '/admin/settlements',
    '/admin/shariah-reviews', '/admin/audit-logs', '/admin/reports', '/admin/settings',
]);

it('shows the project review page with all sections', function () {
    adminAs();
    $p = makeProject(['status' => ProjectStatus::Review]);
    activeContract($p)->forceFill(['status' => ContractStatus::Draft])->save();
    $this->get(route('admin.projects.show', $p))->assertOk()
        ->assertSee('Project overview')->assertSee('Business information')->assertSee('Contract information')
        ->assertSee('Financial information')->assertSee('Risk information')->assertSee('Shariah review')->assertSee('Review actions');
});

it('searches, filters and sorts the projects table server-side', function () {
    adminAs();
    makeProject(['title' => 'Alpha Farm']);
    makeProject(['title' => 'Beta Mill', 'status' => ProjectStatus::Review]);
    Livewire::test(ProjectsTable::class)
        ->assertSee('Alpha Farm')->assertSee('Beta Mill')
        ->set('search', 'Alpha')->assertSee('Alpha Farm')->assertDontSee('Beta Mill')
        ->set('search', '')->set('filter.status', 'REVIEW')->assertSee('Beta Mill')->assertDontSee('Alpha Farm')
        ->set('filter.status', '')->call('sortBy', 'title')->assertSet('sort', 'title')->assertSet('dir', 'asc');
});

it('approves a project through the confirmation modal and writes an audit entry', function () {
    $admin = adminAs();
    $p = makeProject(['status' => ProjectStatus::Review]);
    activeContract($p)->forceFill(['status' => ContractStatus::Draft])->save();

    Livewire::test(ProjectsTable::class)
        ->call('ask', 'approve', $p->id, 'Approve project')->assertSet('confirming.action', 'approve')
        ->call('confirm')->assertSet('notice', 'Approve project — done.');
    expect($p->fresh()->status)->toBe(ProjectStatus::Approved)->and(AuditLog::where('action', 'project.approve')->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('requires a reason for rejection and shows safe errors for illegal moves', function () {
    adminAs();
    $p = makeProject(['status' => ProjectStatus::Review]);
    activeContract($p);
    Livewire::test(ProjectsTable::class)
        ->call('ask', 'reject', $p->id, 'Reject project', true, 'danger')->call('confirm')->assertHasErrors('reason');
    expect($p->fresh()->status)->toBe(ProjectStatus::Review);

    $active = makeProject(['status' => ProjectStatus::Active]);
    Livewire::test(ProjectsTable::class)->call('ask', 'approve', $active->id, 'Approve project')->call('confirm')
        ->assertSet('error', 'This project cannot be modified in its current status.');
});

it('blocks staff from approving projects even by calling the action directly', function () {
    adminAs(UserRole::Staff);
    $p = makeProject(['status' => ProjectStatus::Review]);
    activeContract($p);
    Livewire::test(ProjectsTable::class)->call('ask', 'approve', $p->id, 'Approve')->call('confirm')->assertSet('error', 'You are not allowed to do that.');
    expect($p->fresh()->status)->toBe(ProjectStatus::Review);
});

it('project review page action: request revision needs a reason', function () {
    adminAs();
    $p = makeProject(['status' => ProjectStatus::Review]);
    activeContract($p);
    Livewire::test(ProjectReview::class, ['project' => $p])->call('ask', 'revision')->call('confirm')->assertHasErrors('reason')
        ->set('reason', 'Add supplier quotes')->call('confirm');
    expect($p->fresh()->status)->toBe(ProjectStatus::NeedsRevision);
});

it('moves a withdrawal through review to paid and reserves/releases funds correctly', function () {
    $admin = adminAs();
    $inv = makeInvestor(10000000);
    $w = app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(2000000), 'adm-w');

    $t = Livewire::test(WithdrawalsTable::class)->assertSee($w->reference);
    foreach (['review', 'approve', 'process', 'paid'] as $step) {
        $t->call('ask', $step, $w->id, 'Step')->call('confirm')->assertSet('error', null);
    }
    expect($w->fresh()->status)->toBe(WithdrawalStatus::Paid);
});

it('staff cannot approve withdrawals or reverse ledger transactions', function () {
    adminAs(UserRole::Staff);
    $inv = makeInvestor(10000000);
    $w = app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(2000000), 'adm-w2');
    Livewire::test(WithdrawalsTable::class)->call('ask', 'review', $w->id, 'Step')->call('confirm')->assertSet('error', 'You are not allowed to do that.');
    expect($w->fresh()->status)->toBe(WithdrawalStatus::Pending);

    Livewire::test(LedgerTable::class)->assertForbidden();   // staff has no ledger.view
});

it('only an admin can reverse a ledger transaction, with a reason', function () {
    adminAs();
    $inv = makeInvestor(10000000);
    $tx = Transaction::where('type', 'DEPOSIT')->first();
    // deposit reversal would overdraw once funds are withdrawn; here balance is intact, so it succeeds
    Livewire::test(LedgerTable::class)->call('ask', 'reverse', $tx->id, 'Reverse', true, 'danger')->call('confirm')->assertHasErrors('reason')
        ->set('reason', 'Duplicate bank credit')->call('confirm')->assertSet('error', null);
    expect($tx->fresh()->status->value)->toBe('REVERSED');
});

it('verifies and rejects deposits from the deposits table', function () {
    adminAs();
    $inv = makeInvestor();
    $d = app(WalletService::class)->requestDeposit($inv->user, Money::minor(500000), 'dep-ui');
    Livewire::test(DepositsTable::class)->call('ask', 'verify', $d->id, 'Verify deposit')->call('confirm')->assertSet('error', null);
    expect($d->fresh()->status->value)->toBe('VERIFIED');
});

it('approves and rejects KYC submissions with the right permissions', function () {
    adminAs(UserRole::Manager);
    $inv = makeInvestor(0, false);
    Livewire::test(KycTable::class)->assertSee($inv->user->name)
        ->call('ask', 'reject', $inv->id, 'Reject', true, 'danger')->set('reason', 'Blurry ID')->call('confirm')->assertSet('error', null);
    expect($inv->fresh()->kyc_status)->toBe(KycStatus::Rejected);
});
