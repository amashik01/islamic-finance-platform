<?php

namespace App\Enums;

enum ContractType: string
{
    case Mudarabah = 'MUDARABAH';
    case Musharakah = 'MUSHARAKAH';
    case Murabaha = 'MURABAHA';

    public function label(): string
    {
        return match ($this) {
            self::Mudarabah => 'Mudarabah',
            self::Musharakah => 'Musharakah',
            self::Murabaha => 'Murabaha',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
