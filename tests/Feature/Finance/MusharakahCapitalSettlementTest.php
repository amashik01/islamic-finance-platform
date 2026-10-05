<?php

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Enums\SettlementItemType as Item;
use App\Exceptions\FinancialException;
use App\Models\ManagerRecovery;
use App\Models\MusharakahCapitalContribution;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

/** Real Musharakah: investors 700,000 + business 300,000 (BDT), profit 70/30, capital-ratio loss. Returns [contract, investor, project]. */
function realMusharakah(): array
{
    $project = realProject(ContractType::Musharakah);
    $investor = makeInvestor(80000000);
    fund($investor, $project, 70000000);
    $contract = $project->contract->fresh();
    recordBusinessCapital($contract);

    return [$contract->fresh(), $investor, $project->fresh()];
}

function capShariahReviewer(): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->givePermissionTo('shariah.review');

    return $u;
}

function sum($s, Item $t): int { return (int) $s->items->where('item_type', $t)->sum('amount'); }

it('12. business capital is a ledger-backed contribution held in custody as the partner\'s capital at risk', function () {
    [$c, , $p] = realMusharakah();
    $row = MusharakahCapitalContribution::where('contract_id', $c->id)->firstOrFail();
    expect($row->amount)->toBe(30000000)->and($row->transaction_id)->not->toBeNull()
        ->and(pool(A::BusinessCapital, $p->id))->toBe(30000000)->and(pool(A::ProjectFunds, $p->id))->toBe(0)->and(pool(A::VentureCapital, $p->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('13. Musharakah profit 200,000 at 70/30 settles: investor 700,000+140,000, business 300,000+60,000, nothing left behind', function () {
    [$c, $inv, $p] = realMusharakah();
    $s = settleNow($c, 20000000);
    $w = app(WalletService::class);

    expect(sum($s, Item::Principal))->toBe(70000000)->and(sum($s, Item::InvestmentProfit))->toBe(14000000)
        ->and(sum($s, Item::BusinessCapitalReturn))->toBe(30000000)->and(sum($s, Item::BusinessProfitShare))->toBe(6000000)
        ->and($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 70000000 + 14000000)
        ->and(pool(A::BusinessFunds, $p->id))->toBe(30000000 + 6000000)   // business capital back + 60,000 profit share, payable
        ->and(pool(A::ProjectFunds, $p->id))->toBe(0)->and(pool(A::VentureCapital, $p->id))->toBe(0)->and(pool(A::BusinessCapital, $p->id))->toBe(0)
        ->and($c->fresh()->status)->toBe(ContractStatus::Completed)
        ->and(MusharakahCapitalContribution::where('contract_id', $c->id)->value('status'))->toBe(\App\Enums\CapitalContributionStatus::Settled);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('14-16. Musharakah loss 100,000: investor gets 630,000 back (loses 70,000), business 270,000 (loses 30,000); no debt, no recovery, no Clearing, no platform cash', function () {
    [$c, $inv, $p] = realMusharakah();
    $s = settleNow($c, -10000000);
    $w = app(WalletService::class);

    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 63000000)
        ->and(sum($s, Item::Principal))->toBe(63000000)->and(sum($s, Item::Adjustment))->toBe(-7000000)
        ->and(sum($s, Item::BusinessCapitalReturn))->toBe(27000000)->and(sum($s, Item::BusinessCapitalLoss))->toBe(-3000000)
        ->and(ManagerRecovery::count())->toBe(0)
        ->and(pool(A::VentureCapital, $p->id))->toBe(0)->and(pool(A::ProjectFunds, $p->id))->toBe(0)->and(pool(A::BusinessCapital, $p->id))->toBe(0)
        ->and(pool(A::BusinessFunds, $p->id))->toBe(27000000)
        ->and((int) \App\Models\LedgerAccount::where('type', A::Clearing)->sum('balance'))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::PlatformCash)->count())->toBe(0)
        ->and(\App\Models\Transaction::where('type', 'LOSS_RECOGNITION')->sum('amount'))->toBe(10000000);   // economic loss = |net|, recognised once
    // Custody now holds exactly what the participants may still draw: 630,000 (wallet balance incl. 100,000 left) + 270,000 business.
    expect((int) \App\Models\LedgerAccount::where('type', A::CustodyCash)->value('balance'))->toBe(10000000 + 63000000 + 27000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('the audited example: investor 100,000 + business 50,000, loss 30,000 -> investor gets 80,000, business 40,000, nothing in Clearing', function () {
    $project = realProject(ContractType::Musharakah, ['total_capital' => '150000', 'investor_contribution' => '100000', 'business_contribution' => '50000']);
    $inv = makeInvestor(10000000);
    fund($inv, $project, 10000000);
    recordBusinessCapital($project->contract->fresh());
    $s = settleNow($project->contract->fresh(), -3000000);
    $w = app(WalletService::class);

    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(8000000)
        ->and(sum($s, Item::Adjustment))->toBe(-2000000)->and(sum($s, Item::BusinessCapitalLoss))->toBe(-1000000)
        ->and(pool(A::BusinessFunds, $project->id))->toBe(4000000)
        ->and((int) \App\Models\LedgerAccount::where('type', A::Clearing)->sum('balance'))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::CustodyCash)->value('balance'))->toBe(12000000);   // 80,000 + 40,000: the books equal what is owed
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('17. activation is refused without a recorded business contribution', function () {
    $project = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $project, 70000000);
    expect($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Funding)->and($project->contract->fresh()->status)->toBe(ContractStatus::Approved);
});

it('18. a duplicate contribution and a mismatched amount are refused', function () {
    $project = realProject(ContractType::Musharakah);
    $c = $project->contract->fresh();
    expect(fn () => recordBusinessCapital($c, 29999999))->toThrow(FinancialException::class);
    recordBusinessCapital($c);
    expect(fn () => recordBusinessCapital($c->fresh()))->toThrow(FinancialException::class)
        ->and(MusharakahCapitalContribution::where('contract_id', $c->id)->count())->toBe(1);
});

it('18b. the same idempotency key replays the contribution without a second ledger movement', function () {
    $project = realProject(ContractType::Musharakah);
    $c = $project->contract->fresh();
    $svc = app(\App\Services\Contract\MusharakahCapitalService::class);
    $by = User::factory()->create();
    $a = $svc->recordBusinessContribution($c, Money::minor(30000000), 'same-key', $by);
    $b = $svc->recordBusinessContribution($c->fresh(), Money::minor(30000000), 'same-key', $by);
    expect($b->id)->toBe($a->id)->and(pool(A::BusinessCapital, $project->id))->toBe(30000000);
});

it('19. an agreed (non-capital) loss ratio is refused for a non-legacy contract', function () {
    [$c] = realMusharakah();
    $c->musharakah->forceFill(['loss_allocation_basis' => \App\Enums\LossAllocationBasis::AgreedRatio, 'loss_exception_reason' => null])->save();
    closeOut($c, -10000000);
    expect(fn () => app(SettlementService::class)->settle($c->fresh(), Money::minor(-10000000), User::factory()->create()))->toThrow(FinancialException::class, 'not supported for new contracts');
});

it('19b. the agreed loss exception cannot be approved, so a real contract always settles by capital ratio', function () {
    $project = realProject(ContractType::Musharakah, ['investor_profit' => '50', 'business_profit' => '50']);
    expect(fn () => app(\App\Services\Finance\MusharakahLossException::class)->approve($project->contract->musharakah, capShariahReviewer(), 'Scholar panel resolution 14: loss shared equally for this venture.'))->toThrow(FinancialException::class, 'frozen');
    fund(makeInvestor(80000000), $project, 70000000);
    recordBusinessCapital($project->contract->fresh());
    $c = $project->contract->fresh();
    $s = settleNow($c, -10000000);
    expect(sum($s, Item::Adjustment))->toBe(-7000000)->and(sum($s, Item::BusinessCapitalLoss))->toBe(-3000000);   // 70/30 capital ratio despite a 50/50 profit ratio
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('settlement is refused until the capital is deployed and the remittances imply the result', function () {
    [$c] = realMusharakah();
    $admin = User::factory()->create();
    expect(fn () => app(SettlementService::class)->settle($c, Money::minor(20000000), $admin))->toThrow(FinancialException::class, 'not been deployed');
    deployCapital($c);
    returnCapital($c, 100000000);
    expect(fn () => app(SettlementService::class)->settle($c->fresh(), Money::minor(20000000), $admin))->toThrow(FinancialException::class, 'imply a result of BDT 0.00');
});

/* ---- Mudarabah 7-11 ---- */

it('7-11. Mudarabah profit 20,000 at 70/30 on 100,000: investor 100,000+14,000, Mudarib share 6,000 payable, nothing left behind', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(20000000);
    fund($inv, $project, 10000000);
    $c = $project->contract->fresh();
    expect($c->status)->toBe(ContractStatus::Active);
    $s = settleNow($c, 2000000);
    $w = app(WalletService::class);

    expect(sum($s, Item::Principal))->toBe(10000000)->and(sum($s, Item::InvestmentProfit))->toBe(1400000)->and(sum($s, Item::BusinessProfitShare))->toBe(600000)
        ->and($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 10000000 + 1400000)
        ->and(pool(A::BusinessFunds, $project->id))->toBe(600000)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(0)->and(pool(A::VentureCapital, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('Mudarabah ordinary loss 20,000 on 100,000: investor gets 80,000; no Mudarib debt, no recovery, no Clearing, no fake cash', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(10000000);
    fund($inv, $project, 10000000);
    $s = settleNow($project->contract->fresh(), -2000000);
    $w = app(WalletService::class);

    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(8000000)->and(ManagerRecovery::count())->toBe(0)
        ->and(pool(A::VentureCapital, $project->id))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::Clearing)->sum('balance'))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::CustodyCash)->value('balance'))->toBe(8000000)   // custody equals what the investor can withdraw
        ->and(\App\Models\Transaction::where('type', 'LOSS_RECOGNITION')->sum('amount'))->toBe(2000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('a settlement cannot run twice for the same contract', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $c = $project->contract->fresh();
    settleNow($c, 0);
    expect(fn () => app(SettlementService::class)->settle($c->fresh(), Money::minor(0), User::factory()->create()))->toThrow(FinancialException::class);
});
