<?php

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Exceptions\FinancialException;
use App\Services\Project\ProjectBuilder;
use App\Support\Percent;

function baseData(array $extra = []): array
{
    return $extra + ['title' => 'Test Project', 'description' => 'Desc', 'industry' => 'Retail', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'key_risks' => 'Demand', 'minimum_amount' => '5000'];
}

function mud(array $o = []): array
{
    return baseData($o + ['contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30']);
}

function msk(array $o = []): array
{
    return baseData($o + ['contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '60', 'business_profit' => '40', 'loss_basis' => 'CAPITAL_RATIO']);
}

function mrb(array $o = []): array
{
    return baseData($o + ['contract_type' => 'MURABAHA', 'asset_name' => 'Refrigerators', 'supplier' => 'Supplier Ltd', 'quantity' => 4, 'unit_cost' => '25000', 'sale_profit' => '10000', 'installments' => 4]);
}

it('parses percentages to basis points without floats', function () {
    expect(Percent::toBps('70'))->toBe(7000)->and(Percent::toBps('62.5'))->toBe(6250)->and(Percent::format(6250))->toBe('62.5%');
    expect(fn () => Percent::toBps('101'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Percent::toBps('abc'))->toThrow(InvalidArgumentException::class);
});

it('saves a mudarabah draft with the agreed ratio', function () {
    $p = app(ProjectBuilder::class)->saveDraft(makeBusiness(), mud());
    expect($p->status)->toBe(ProjectStatus::Draft)->and($p->funding_target)->toBe(10000000)
        ->and($p->contract->mudarabah->investor_profit_bps)->toBe(7000)->and($p->contract->mudarabah->business_profit_bps)->toBe(3000);
});

it('rejects mudarabah ratios that do not total 100%', function () {
    app(ProjectBuilder::class)->saveDraft(makeBusiness(), mud(['business_profit' => '20']));
})->throws(FinancialException::class, 'total 100%');

it('saves a musharakah draft with ownership separate from profit ratio', function () {
    $m = app(ProjectBuilder::class)->saveDraft(makeBusiness(), msk())->contract->musharakah;
    expect($m->investor_ownership_bps)->toBe(7000)->and($m->investor_profit_bps)->toBe(6000)->and($m->total_capital)->toBe(100000000);
});

it('rejects musharakah contributions that do not add up to the total', function () {
    app(ProjectBuilder::class)->saveDraft(makeBusiness(), msk(['total_capital' => '900000']));
})->throws(FinancialException::class, 'add up');

it('derives murabaha cost, sale price and profit from the asset', function () {
    $p = app(ProjectBuilder::class)->saveDraft(makeBusiness(), mrb());
    $m = $p->contract->murabaha;
    expect($m->purchase_cost)->toBe(10000000)->and($m->sale_profit)->toBe(1000000)->and($m->sale_price)->toBe(11000000)
        ->and($m->assets()->count())->toBe(1)->and($p->minimum_amount)->toBe(10000000);
});

it('rejects an inconsistent murabaha purchase cost', function () {
    app(ProjectBuilder::class)->saveDraft(makeBusiness(), mrb(['purchase_cost' => '90000']));
})->throws(FinancialException::class, 'quantity');

it('refuses a minimum above the target and edits to non-draft projects', function () {
    $b = makeBusiness();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($b, mud(['minimum_amount' => '200000'])))->toThrow(FinancialException::class, 'minimum');
    $p = app(ProjectBuilder::class)->saveDraft($b, mud());
    $p->forceFill(['status' => ProjectStatus::Review])->save();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($b, mud(['title' => 'Changed']), $p))->toThrow(FinancialException::class, 'current status');
});

it('refuses to edit another business\'s project', function () {
    $p = app(ProjectBuilder::class)->saveDraft(makeBusiness(), mud());
    app(ProjectBuilder::class)->saveDraft(makeBusiness(), mud(), $p);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('updating a draft keeps one project and one contract', function () {
    $b = makeBusiness();
    $svc = app(ProjectBuilder::class);
    $p = $svc->saveDraft($b, mud());
    $svc->saveDraft($b, mud(['title' => 'Renamed', 'capital_required' => '200000']), $p);
    expect(\App\Models\Project::count())->toBe(1)->and(\App\Models\Contract::count())->toBe(1)->and($p->fresh()->funding_target)->toBe(20000000)->and($p->fresh()->title)->toBe('Renamed');
});
