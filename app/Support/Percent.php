<?php

namespace App\Support;

use InvalidArgumentException;

/** Parses user percentages like "70" or "62.5" into basis points (7000, 6250) without floats. */
final class Percent
{
    public static function toBps(string $value): int
    {
        $value = trim(str_replace('%', '', $value));
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException('Enter a percentage such as 70 or 62.5.');
        }
        $bps = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        if ($bps > 10000) {
            throw new InvalidArgumentException('A percentage cannot exceed 100.');
        }

        return $bps;
    }

    public static function format(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.').'%';
    }
}
