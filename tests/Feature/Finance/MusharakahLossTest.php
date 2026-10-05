<?php

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\LossAllocationBasis;
use App\Enums\SettlementItemType as Item;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Finance\MusharakahLossException;
use App\Services\Project\ProjectBuilder;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

/** Investor 700,000 + business 300,000 = 1,000,000; profit 50/50; investor funds 700,000 through the platform. */
function musharakahFixture(): array
{
    $project = makeProject(['funding_target' => 70000000, 'contract_type' => ContractType::Musharakah]);
    $contract = activeContract($project);
    $inv = makeInvestor(80000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(70000000), 'mk-'.uniqid());

    return [$contract->fresh(), $inv, $project];
}

function shariahReviewer(): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->givePermissionTo('shariah.review');

    return $u;
}

it('derives ownership from capital and keeps the profit ratio independent', function () {
    $o = app(\App\Services\Finance\MusharakahProfitCalculator::class)->ownership(Money::minor(70000000), Money::minor(30000000));
    expect($o['total']->minor)->toBe(100000000)->and($o['investor_ownership_bps'])->toBe(7000)->and($o['business_ownership_bps'])->toBe(3000);
    [$contract] = musharakahFixture();
    expect($contract->musharakah->investor_profit_bps)->toBe(5000);   // profit ratio is its own agreed term, not the capital share
});

it('profit is distributed by the agreed profit ratio, business share recorded', function () {
    [$contract, $inv, $project] = musharakahFixture();
    remit($contract, 10000000);
    $s = app(SettlementService::class)->settle($contract, Money::minor(10000000), User::factory()->create());
    expect((int) $s->items->where('item_type', Item::InvestmentProfit)->sum('amount'))->toBe(5000000)
        ->and((int) $s->items->where('item_type', Item::BusinessProfitShare)->sum('amount'))->toBe(5000000)
        ->and((int) $s->items->where('item_type', Item::Principal)->sum('amount'))->toBe(70000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('an ordinary loss is allocated by capital contribution ratio (investor bears 70%)', function () {
    [$contract, $inv] = musharakahFixture();
    expect($contract->musharakah->loss_allocation_basis)->toBe(LossAllocationBasis::CapitalRatio);
    $s = app(SettlementService::class)->settle($contract, Money::minor(-10000000), User::factory()->create());
    $w = app(WalletService::class);
    expect($w->balances($w->walletFor($inv->user))['available']->minor)->toBe(10000000 + 70000000 - 7000000)   // balance left + principal back - 70% of the 100,000 loss
        ->and((int) $s->items->where('item_type', Item::Adjustment)->sum('amount'))->toBe(-7000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('the application form cannot select an agreed loss ratio', function () {
    $b = makeBusiness();
    $d = ['title' => 'Mk', 'description' => 'Desc', 'industry' => 'X', 'purpose' => 'Y', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'minimum_amount' => '5000',
        'contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '60', 'business_profit' => '40',
        'loss_basis' => 'AGREED_RATIO'];   // a tampered request
    $p = app(ProjectBuilder::class)->saveDraft($b, $d);
    expect($p->contract->musharakah->loss_allocation_basis)->toBe(LossAllocationBasis::CapitalRatio);
    expect(array_key_exists('form.loss_basis', \App\Support\ProjectFormRules::forStep(3, 'MUSHARAKAH')))->toBeFalse();
});

it('settlement refuses an agreed ratio that has no documented Shariah approval', function () {
    [$contract] = musharakahFixture();
    corrupt('musharakah_contracts', ['contract_id' => $contract->id], ['loss_allocation_basis' => 'AGREED_RATIO']);   // bypassing the workflow
    expect(fn () => app(SettlementService::class)->settle($contract->fresh(), Money::minor(-10000000), User::factory()->create()))->toThrow(FinancialException::class, 'Shariah approval');
});

it('an exception needs a Shariah reviewer, a documented reason, and must precede the contract start', function () {
    $svc = app(MusharakahLossException::class);
    $project = makeProject(['contract_type' => ContractType::Musharakah]);
    $contract = activeContract($project);
    $contract->forceFill(['status' => ContractStatus::Approved])->save();
    $terms = $contract->musharakah;
    $reason = 'Scholar panel resolution 14: loss shared equally for this venture.';

    expect(fn () => $svc->approve($terms, User::factory()->create(), $reason))->toThrow(FinancialException::class, 'Shariah reviewer')
        ->and(fn () => $svc->approve($terms, shariahReviewer(), 'too short'))->toThrow(FinancialException::class, 'Document the Shariah basis');
    expect($terms->fresh()->loss_allocation_basis)->toBe(LossAllocationBasis::CapitalRatio);

    $svc->approve($terms, $reviewer = shariahReviewer(), $reason);
    $t = $terms->fresh();
    expect($t->loss_allocation_basis)->toBe(LossAllocationBasis::AgreedRatio)->and($t->loss_exception_approved_by)->toBe($reviewer->id)->and($t->loss_exception_reason)->toBe($reason);
    expect(AuditLog::where('action', 'musharakah.loss_exception_approved')->count())->toBe(1);

    $contract->forceFill(['status' => ContractStatus::Active])->save();
    expect(fn () => $svc->approve($terms->fresh(), shariahReviewer(), $reason))->toThrow(FinancialException::class, 'once the contract has started');
});

it('with an approved exception the agreed ratio is honoured and audited', function () {
    [$contract, $inv] = musharakahFixture();
    $contract->forceFill(['status' => ContractStatus::Approved])->save();
    app(MusharakahLossException::class)->approve($contract->musharakah, shariahReviewer(), 'Scholar panel resolution 14: loss shared equally for this venture.');
    $contract->forceFill(['status' => ContractStatus::Active])->save();

    $s = app(SettlementService::class)->settle($contract->fresh(), Money::minor(-10000000), User::factory()->create());
    expect((int) $s->items->where('item_type', Item::Adjustment)->sum('amount'))->toBe(-5000000);   // 50/50, not 70/30
});
