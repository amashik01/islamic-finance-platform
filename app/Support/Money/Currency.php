<?php

namespace App\Support\Money;

use App\Exceptions\FinancialException;
use App\Exceptions\NonBdtCurrencyException;

/**
 * The platform has exactly one operational currency: BDT (Bangladeshi Taka).
 * There is no multi-currency support, no FX and no conversion. This class is the single canonical
 * definition; display may use "৳" or "BDT", but the domain layer only ever stores the code below.
 */
final readonly class Currency
{
    public const CODE = 'BDT';

    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public int $minorUnits,
    ) {}

    public static function of(?string $code = null): self
    {
        self::assertBdt($code ?? self::CODE);
        $c = config('finance.currency');

        return new self(self::CODE, $c['name'], $c['symbol'], $c['minor_units']);
    }

    /** Domain-level guard (value objects, parsing). */
    public static function assertBdt(?string $code): void
    {
        if ($code !== self::CODE) {
            throw new NonBdtCurrencyException('Only BDT (Bangladeshi Taka) is supported.');
        }
    }

    /** Service-level guard: a user-safe FinancialException naming what was wrong. */
    public static function require(?string $code, string $what = 'This amount'): void
    {
        if ($code !== self::CODE) {
            throw new FinancialException("$what must be in BDT. No other currency is supported.");
        }
    }

    public function factor(): int
    {
        return 10 ** $this->minorUnits;
    }
}
