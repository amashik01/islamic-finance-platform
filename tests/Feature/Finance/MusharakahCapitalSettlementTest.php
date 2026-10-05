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

it('12. business capital is a ledger-backed contribution (cash in, pool up)', function () {
    [$c, , $p] = realMusharakah();
    $row = MusharakahCapitalContribution::where('contract_id', $c->id)->firstOrFail();
    expect($row->amount)->toBe(30000000)->and($row->transaction_id)->not->toBeNull()
        ->and(pool(A::ProjectFunds, $p->id))->toBe(100000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('13. Musharakah profit 200,000 at 70/30 settles: investor 700,000+140,000, business 300,000+60,000', function () {
    [$c, $inv, $p] = realMusharakah();
    remit($c, 20000000);
    $s = app(SettlementService::class)->settle($c, Money::minor(20000000), User::factory()->create());
    $w = app(WalletService::class);

    expect(sum($s, Item::Principal))->toBe(70000000)->and(sum($s, Item::InvestmentProfit))->toBe(14000000)
        ->and(sum($s, Item::BusinessCapitalReturn))->toBe(30000000)->and(sum($s, Item::BusinessProfitShare))->toBe(6000000)
        ->and($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 70000000 + 14000000)
        ->and(pool(A::BusinessFunds, $p->id))->toBe(30000000 + 6000000)   // business capital back + 60,000 profit share
        ->and(pool(A::ProjectFunds, $p->id))->toBe(0)
        ->and($c->fresh()->status)->toBe(ContractStatus::Completed)
        ->and(MusharakahCapitalContribution::where('contract_id', $c->id)->value('status'))->toBe(\App\Enums\CapitalContributionStatus::Settled);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('14-16. Musharakah loss 100,000: investor gets 630,000 back (loses 70,000), business 270,000 (loses 30,000), no business debt', function () {
    [$c, $inv, $p] = realMusharakah();
    $s = app(SettlementService::class)->settle($c, Money::minor(-10000000), User::factory()->create());
    $w = app(WalletService::class);

    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 63000000)
        ->and(sum($s, Item::Principal))->toBe(63000000)->and(sum($s, Item::Adjustment))->toBe(-7000000)
        ->and(sum($s, Item::BusinessCapitalReturn))->toBe(27000000)->and(sum($s, Item::BusinessCapitalLoss))->toBe(-3000000)
        ->and(ManagerRecovery::count())->toBe(0)
        ->and(pool(A::ProjectFunds, $p->id))->toBe(0);   // 63,000,000 + 27,000,000 returned of 100,000,000 - 10,000,000 lost
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
    expect($b->id)->toBe($a->id)->and(pool(A::ProjectFunds, $project->id))->toBe(30000000);
});

it('19. the exceptional loss ratio cannot be used without Shariah approval', function () {
    [$c] = realMusharakah();
    $c->musharakah->forceFill(['loss_allocation_basis' => \App\Enums\LossAllocationBasis::AgreedRatio, 'loss_exception_reason' => null])->save();
    expect(fn () => app(SettlementService::class)->settle($c->fresh(), Money::minor(-10000000), User::factory()->create()))->toThrow(FinancialException::class);
});

it('19b. an approved exception made before activation is honoured on a real contract (50/50 loss)', function () {
    $project = realProject(ContractType::Musharakah, ['investor_profit' => '50', 'business_profit' => '50']);
    app(\App\Services\Finance\MusharakahLossException::class)->approve($project->contract->musharakah, capShariahReviewer(), 'Scholar panel resolution 14: loss shared equally for this venture.');
    fund(makeInvestor(80000000), $project, 70000000);
    recordBusinessCapital($project->contract->fresh());
    $c = $project->contract->fresh();
    expect($c->status)->toBe(ContractStatus::Active);
    $s = app(SettlementService::class)->settle($c, Money::minor(-10000000), User::factory()->create());
    expect(sum($s, Item::Adjustment))->toBe(-5000000)->and(sum($s, Item::BusinessCapitalLoss))->toBe(-5000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('20. the loss exception cannot be revoked once the contract is active', function () {
    [$c] = realMusharakah();
    expect($c->fresh()->status)->toBe(ContractStatus::Active);
    expect(fn () => app(\App\Services\Finance\MusharakahLossException::class)->revoke($c->musharakah, User::factory()->create(), 'changed mind'))->toThrow(FinancialException::class);
});

it('settlement without business remittance cannot pay profit; the shortfall is explained', function () {
    [$c] = realMusharakah();
    expect(fn () => app(SettlementService::class)->settle($c, Money::minor(20000000), User::factory()->create()))->toThrow(FinancialException::class, 'remittance');
});

/* ---- Mudarabah 7-11 ---- */

it('7-11. Mudarabah profit 20,000 at 70/30 on 100,000: investor 100,000+14,000, business share 6,000, pool empty after payouts', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(20000000);
    fund($inv, $project, 10000000);
    $c = $project->contract->fresh();
    expect($c->status)->toBe(ContractStatus::Active);
    remit($c, 2000000);
    $s = app(SettlementService::class)->settle($c, Money::minor(2000000), User::factory()->create());
    $w = app(WalletService::class);

    expect(sum($s, Item::Principal))->toBe(10000000)->and(sum($s, Item::InvestmentProfit))->toBe(1400000)->and(sum($s, Item::BusinessProfitShare))->toBe(600000)
        ->and($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 10000000 + 1400000)
        ->and(pool(A::BusinessFunds, $project->id))->toBe(600000)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(0)
        ->and(pool(A::CapitalDeployed, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('Mudarabah loss is borne by the investor, not the manager (absent fault)', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(20000000);
    fund($inv, $project, 10000000);
    $c = $project->contract->fresh();
    $s = app(SettlementService::class)->settle($c, Money::minor(-1000000), User::factory()->create());
    $w = app(WalletService::class);
    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 9000000)->and(ManagerRecovery::count())->toBe(0)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('a settlement cannot run twice for the same contract', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $c = $project->contract->fresh();
    app(SettlementService::class)->settle($c, Money::minor(0), User::factory()->create());
    expect(fn () => app(SettlementService::class)->settle($c->fresh(), Money::minor(0), User::factory()->create()))->toThrow(FinancialException::class);
});
