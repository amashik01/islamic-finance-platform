<?php

namespace App\Enums;

enum RiskLevel: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Lower risk',
            self::Medium => 'Moderate risk',
            self::High => 'Higher risk',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
