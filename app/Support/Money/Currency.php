<?php

namespace App\Support\Money;

use InvalidArgumentException;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public int $minorUnits,
    ) {}

    public static function of(?string $code = null): self
    {
        $code ??= config('finance.default_currency');
        $c = config("finance.currencies.$code") ?? throw new InvalidArgumentException("Unsupported currency [$code].");

        return new self($code, $c['name'], $c['symbol'], $c['minor_units']);
    }

    public function factor(): int
    {
        return 10 ** $this->minorUnits;
    }
}
