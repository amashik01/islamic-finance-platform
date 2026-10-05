<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Immutable money value in integer minor units (e.g. paisa). Never uses floats.
 * Ratios are expressed in basis points (10000 = 100%).
 */
final readonly class Money implements \JsonSerializable
{
    public const BPS = 10000;

    private function __construct(public int $minor, public string $currency) {}

    public static function minor(int $minor, ?string $currency = null): self
    {
        return new self($minor, $currency ?? config('finance.default_currency'));
    }

    public static function zero(?string $currency = null): self
    {
        return self::minor(0, $currency);
    }

    /** Parse a decimal string such as "1,000.50" without floating point. */
    public static function parse(string $value, ?string $currency = null): self
    {
        $cur = Currency::of($currency);
        $value = str_replace([',', ' '], '', trim($value));
        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money amount [$value].");
        }
        $frac = $m[3] ?? '';
        if (strlen($frac) > $cur->minorUnits) {
            throw new InvalidArgumentException("Too many decimal places for {$cur->code}.");
        }
        $frac = str_pad($frac, $cur->minorUnits, '0');
        $minor = (int) ($m[2].$frac);

        return new self($m[1] === '-' ? -$minor : $minor, $cur->code);
    }

    public function add(self $o): self
    {
        $this->assertSame($o);

        return new self($this->minor + $o->minor, $this->currency);
    }

    public function subtract(self $o): self
    {
        $this->assertSame($o);

        return new self($this->minor - $o->minor, $this->currency);
    }

    /** Share by basis points, rounded half-up (deterministic, integer-only). */
    public function percentOf(int $bps): self
    {
        $product = $this->minor * $bps;
        $q = intdiv(abs($product) * 2 + self::BPS, self::BPS * 2);

        return new self($product < 0 ? -$q : $q, $this->currency);
    }

    /**
     * Split by ratios (basis points, summing to anything >0) so parts always sum to the total.
     * The remainder goes to the first part with the largest fractional remainder (stable).
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);
        if ($total <= 0) {
            throw new InvalidArgumentException('Ratios must sum to a positive number.');
        }
        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($ratios as $i => $r) {
            $share = intdiv($this->minor * $r, $total);
            $parts[$i] = $share;
            $remainders[$i] = ($this->minor * $r) % $total;
            $allocated += $share;
        }
        $left = $this->minor - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left === 0) {
                break;
            }
            $parts[$i] += $left > 0 ? 1 : -1;
            $left += $left > 0 ? -1 : 1;
        }
        ksort($parts);

        return array_map(fn (int $m) => new self($m, $this->currency), array_values($parts));
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function gte(self $o): bool
    {
        $this->assertSame($o);

        return $this->minor >= $o->minor;
    }

    public function lt(self $o): bool
    {
        $this->assertSame($o);

        return $this->minor < $o->minor;
    }

    public function equals(self $o): bool
    {
        return $this->currency === $o->currency && $this->minor === $o->minor;
    }

    /** Decimal string without float math, e.g. "1000.50". */
    public function toDecimal(): string
    {
        $cur = Currency::of($this->currency);
        $abs = abs($this->minor);
        $whole = intdiv($abs, $cur->factor());
        $frac = str_pad((string) ($abs % $cur->factor()), $cur->minorUnits, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '').$whole.($cur->minorUnits ? ".$frac" : '');
    }

    public function format(bool $withCode = true): string
    {
        $cur = Currency::of($this->currency);
        $dec = $this->toDecimal();
        [$whole, $frac] = array_pad(explode('.', ltrim($dec, '-')), 2, null);
        $whole = number_format((int) $whole);
        $out = $whole.($frac !== null ? ".$frac" : '');

        return ($this->minor < 0 ? '-' : '').($withCode ? $cur->code.' ' : $cur->symbol).$out;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSame(self $o): void
    {
        if ($o->currency !== $this->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }
    }
}
