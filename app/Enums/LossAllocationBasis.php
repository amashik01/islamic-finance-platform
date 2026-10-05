<?php

namespace App\Enums;

enum LossAllocationBasis: string
{
    case CapitalRatio = 'CAPITAL_RATIO';
    case AgreedRatio = 'AGREED_RATIO';

    public function label(): string
    {
        return match ($this) {
            self::CapitalRatio => 'In proportion to capital contribution',
            self::AgreedRatio => 'Agreed ratio',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
