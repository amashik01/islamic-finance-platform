<?php

namespace App\Enums;

enum EntryDirection: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
