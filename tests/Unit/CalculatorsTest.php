<?php

use App\Enums\LossAllocationBasis;
use App\Enums\SettlementItemType;
use App\Services\Finance\MudarabahProfitCalculator;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Services\Finance\SettlementCalculator;
use App\Support\Money\Money;

const BDT_100K = 10000000;

it('mudarabah: 100k capital, 20k profit, 70/30 split returns principal plus 14k', function () {
    $r = (new MudarabahProfitCalculator)->settle(Money::minor(BDT_100K), Money::minor(2000000), 7000, 3000);
    expect($r['investor_profit']->minor)->toBe(1400000)
        ->and($r['business_profit']->minor)->toBe(600000)
        ->and($r['principal_returned']->minor)->toBe(BDT_100K);
});

it('mudarabah: ratios must total 100%', function () {
    (new MudarabahProfitCalculator)->settle(Money::minor(BDT_100K), Money::minor(1), 7000, 2000);
})->throws(InvalidArgumentException::class);

it('mudarabah: loss falls on capital unless manager is at fault', function () {
    $calc = new MudarabahProfitCalculator;
    $loss = Money::minor(-1000000);
    $plain = $calc->settle(Money::minor(BDT_100K), $loss, 7000, 3000);
    expect($plain['principal_returned']->minor)->toBe(9000000)->and($plain['investor_loss']->minor)->toBe(1000000);

    $fault = $calc->settle(Money::minor(BDT_100K), $loss, 7000, 3000, managerAtFault: true);
    expect($fault['investor_loss']->isZero())->toBeTrue()->and($fault['manager_liability']->minor)->toBe(1000000);
});

it('musharakah: 700k/300k gives 70/30 ownership but profit ratio is separate', function () {
    $c = new MusharakahProfitCalculator;
    $o = $c->ownership(Money::minor(70000000), Money::minor(30000000));
    expect($o['total']->minor)->toBe(100000000)->and($o['investor_ownership_bps'])->toBe(7000);

    $r = $c->settle(Money::minor(70000000), Money::minor(30000000), Money::minor(10000000), 5000, 5000);
    expect($r['investor_profit']->minor)->toBe(5000000)->and($r['investor_return']->minor)->toBe(75000000);
});

it('musharakah: loss follows the contract basis', function () {
    $c = new MusharakahProfitCalculator;
    $byCapital = $c->settle(Money::minor(70000000), Money::minor(30000000), Money::minor(-10000000), 5000, 5000);
    expect($byCapital['investor_loss']->minor)->toBe(7000000);

    // An agreed ratio is refused unless a documented, Shariah-approved exception is passed explicitly.
    expect(fn () => $c->settle(Money::minor(70000000), Money::minor(30000000), Money::minor(-10000000), 5000, 5000, LossAllocationBasis::AgreedRatio))
        ->toThrow(InvalidArgumentException::class, 'capital contribution');
    $byAgreed = $c->settle(Money::minor(70000000), Money::minor(30000000), Money::minor(-10000000), 5000, 5000, LossAllocationBasis::AgreedRatio, lossExceptionApproved: true);
    expect($byAgreed['investor_loss']->minor)->toBe(5000000);
});

it('murabaha: cost 100k + profit 10k = sale price 110k, installments never exceed price', function () {
    $c = new MurabahaSaleCalculator;
    $price = $c->salePrice(Money::minor(BDT_100K), Money::minor(1000000));
    expect($price->minor)->toBe(11000000);
    $inst = $c->installments($price, 3);
    expect(array_sum(array_map(fn ($m) => $m->minor, $inst)))->toBe(11000000)
        ->and($c->outstanding($price, Money::minor(4000000))->minor)->toBe(7000000);
});

it('murabaha: overpayment is rejected', function () {
    (new MurabahaSaleCalculator)->outstanding(Money::minor(100), Money::minor(101));
})->throws(InvalidArgumentException::class);

it('settlement keeps principal, profit and fees as separate lines', function () {
    $r = (new SettlementCalculator)->build([
        'principal' => Money::minor(BDT_100K),
        'investment_profit' => Money::minor(1400000),
        'fee' => Money::minor(10000),
    ], 'BDT');
    expect($r['items'])->toHaveCount(3)
        ->and($r['items'][0]['type'])->toBe(SettlementItemType::Principal)
        ->and($r['net_to_investor']->minor)->toBe(BDT_100K + 1400000 - 10000);
});
