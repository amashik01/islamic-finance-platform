<?php

use App\Support\Money\Money;

it('parses and formats without floats', function () {
    expect(Money::parse('1,000.50')->minor)->toBe(100050)
        ->and(Money::minor(10000)->format())->toBe('BDT 100.00')
        ->and(Money::minor(-5)->toDecimal())->toBe('-0.05');
});

it('rejects excess decimals and bad input', function () {
    Money::parse('1.234');
})->throws(InvalidArgumentException::class);

it('allocates so that parts always sum to the total', function () {
    $parts = Money::minor(100)->allocate([1, 1, 1]);
    expect(array_sum(array_map(fn ($m) => $m->minor, $parts)))->toBe(100);
});

it('rounds percentages half-up deterministically', function () {
    expect(Money::minor(1001)->percentOf(5000)->minor)->toBe(501);
});

it('refuses mixed currencies', function () {
    Money::minor(1, 'BDT')->add(Money::minor(1, 'USD'));
})->throws(InvalidArgumentException::class);
