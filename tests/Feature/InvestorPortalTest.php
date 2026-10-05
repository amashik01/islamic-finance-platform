<?php

use App\Enums\DocumentCategory;
use App\Enums\KycStatus;
use App\Livewire\Investor\InvestmentDetails;
use App\Livewire\Investor\Opportunities;
use App\Livewire\Investor\Wallet;
use App\Livewire\Investor\Withdrawals;
use App\Livewire\Portal\DocumentManager;
use App\Livewire\Portal\NotificationCenter;
use App\Models\Investment;
use App\Models\Withdrawal;
use App\Services\Wallet\InvestmentService;
use App\Support\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders every investor page', function (string $uri) {
    $inv = makeInvestor(10000000);
    $this->actingAs($inv->user)->get($uri)->assertOk();
})->with(['/investor', '/investor/wallet', '/investor/opportunities', '/investor/investments', '/investor/contracts', '/investor/transactions', '/investor/withdrawals', '/investor/documents', '/investor/notifications', '/investor/profile']);

it('shows an investor their own balances with plain-language explanations', function () {
    $inv = makeInvestor(10000000);
    $this->actingAs($inv->user)->get('/investor')->assertSee('Available Balance')->assertSee('BDT 100,000.00')
        ->assertSee('Principal currently placed')->assertSee('Profit actually paid out');
});

it('invests from the modal and shows the new balance', function () {
    $inv = makeInvestor(10000000);
    $p = makeProject();
    prepareFixtureProject($p);
    $this->actingAs($inv->user);
    Livewire::test(Opportunities::class)->call('startInvest', $p->id)->set('amount', '25,000')->call('review')->assertSet('error', null)->assertSee('Investment agreement')
        ->call('confirm')->assertSet('error', 'Confirm that you have read the agreement and accept the disclosure.')
        ->set('consent', true)->set('typedName', $inv->user->name)->set('password', 'password')->call('confirm')
        ->assertSet('error', null)->assertSee('Investment confirmed');
    expect(Investment::count())->toBe(1)->and($p->fresh()->funded_amount)->toBe(2500000);
});

it('a double click on confirm cannot create two investments', function () {
    $inv = makeInvestor(10000000);
    $p = makeProject();
    prepareFixtureProject($p);
    $this->actingAs($inv->user);
    $c = Livewire::test(Opportunities::class)->call('startInvest', $p->id)->set('amount', '10000')->call('review')
        ->set('consent', true)->set('typedName', $inv->user->name)->set('password', 'password');
    $key = $c->get('idempotencyKey');
    $doc = $c->get('documentId');
    $c->call('confirm');
    // Replay the same attempt (slow network, impatient second click)
    $c->set('projectId', $p->id)->set('documentId', $doc)->set('idempotencyKey', $key)->call('confirm');
    expect(Investment::count())->toBe(1);
});

it('shows clear errors for insufficient balance, bad amounts and closed projects', function () {
    $inv = makeInvestor(1000000);
    $p = makeProject();
    prepareFixtureProject($p);
    $this->actingAs($inv->user);
    Livewire::test(Opportunities::class)->call('startInvest', $p->id)->set('amount', '50000')->call('review')
        ->set('consent', true)->set('typedName', $inv->user->name)->set('password', 'password')->call('confirm')->assertSet('error', 'Insufficient available balance.');
    Livewire::test(Opportunities::class)->call('startInvest', $p->id)->set('amount', 'abc')->call('review')->assertHasErrors('amount');
    $closed = makeProject(['status' => \App\Enums\ProjectStatus::Active]);
    Livewire::test(Opportunities::class)->call('startInvest', $closed->id)->set('amount', '6000')->call('review')->assertSet('error', 'This investment is no longer accepting funds.');
});

it('does not offer Murabaha as an investment', function () {
    $inv = makeInvestor(10000000);
    makeProject(['contract_type' => \App\Enums\ContractType::Murabaha, 'title' => 'Shop equipment financing']);
    $this->actingAs($inv->user)->get('/investor/opportunities')->assertSee('not a pooled investment')->assertDontSee('wire:click="startInvest');
});

it('blocks one investor from another investor\'s investment details (IDOR)', function () {
    $a = makeInvestor(10000000);
    $b = makeInvestor();
    $investment = fund($a, makeProject(), 1000000, 'idor');
    $this->actingAs($b->user)->get(route('investor.investments.show', $investment))->assertForbidden();
    $this->actingAs($a->user)->get(route('investor.investments.show', $investment))->assertOk()->assertSee('Financial summary')->assertSee('Activity timeline');
    Livewire::actingAs($b->user)->test(InvestmentDetails::class, ['investment' => $investment])->assertForbidden();
});

it('lists only the investor\'s own investments', function () {
    $a = makeInvestor(10000000);
    $b = makeInvestor(10000000);
    $svc = app(InvestmentService::class);
    fund($a, makeProject(['title' => 'Alpha Only']), 1000000, 'a1');
    fund($b, makeProject(['title' => 'Beta Secret']), 1000000, 'b1');
    $this->actingAs($a->user)->get('/investor/investments')->assertSee('Alpha Only')->assertDontSee('Beta Secret');
});

it('requests a deposit idempotently and explains it is pending verification', function () {
    $inv = makeInvestor();
    $this->actingAs($inv->user);
    Livewire::test(Wallet::class)->set('depositAmount', '5,000')->call('requestDeposit')->assertSee('once we verify');
    expect(\App\Models\Deposit::count())->toBe(1);
});

it('requests, then cancels a withdrawal with friendly errors', function () {
    $inv = makeInvestor(10000000);
    $this->actingAs($inv->user);
    $c = Livewire::test(Withdrawals::class)->set('amount', '150000')->call('submit')->assertSet('error', 'Your withdrawal amount exceeds your eligible balance.');
    $c->set('amount', '20000')->call('submit')->assertSet('error', null)->assertSee('Funds are reserved');
    $w = Withdrawal::first();
    $c->call('cancel', $w->id);
    expect($w->fresh()->status->value)->toBe('CANCELLED');
});

it('another investor cannot cancel my withdrawal', function () {
    $a = makeInvestor(10000000);
    $b = makeInvestor();
    $w = app(\App\Services\Wallet\WalletService::class)->requestWithdrawal($a->user, Money::minor(200000), 'wcx');
    Livewire::actingAs($b->user)->test(Withdrawals::class)->call('cancel', $w->id)->assertForbidden();
});

it('uploads a KYC document and submits for verification via the UI', function () {
    Storage::fake('private');
    $inv = makeInvestor(0, false);
    $inv->forceFill(['kyc_status' => KycStatus::NotSubmitted])->save();
    $this->actingAs($inv->user);
    $pdf = UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\n%%EOF");
    Livewire::test(DocumentManager::class)->set('title', 'National ID')->set('category', 'KYC')->set('file', $pdf)->call('upload')->assertSee('Document uploaded')
        ->call('submitKyc')->assertSee('Submitted for verification');
    expect($inv->fresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('shows friendly errors for a bad upload without leaking technical detail', function () {
    Storage::fake('private');
    $inv = makeInvestor(0, false);
    $this->actingAs($inv->user);
    Livewire::test(DocumentManager::class)->set('title', 'x')->set('file', UploadedFile::fake()->createWithContent('a.pdf', '<?php echo 1;'))->call('upload')
        ->assertSet('error', 'Only PDF, JPG and PNG files are accepted.');
});

it('reads and marks notifications', function () {
    $inv = makeInvestor();
    $inv->user->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'data' => ['title' => 'Investment confirmed', 'message' => 'Done']]);
    $this->actingAs($inv->user);
    Livewire::test(NotificationCenter::class)->assertSee('Investment confirmed')->call('markAllRead');
    expect($inv->user->unreadNotifications()->count())->toBe(0);
});
