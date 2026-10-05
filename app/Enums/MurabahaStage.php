<?php

namespace App\Enums;

enum MurabahaStage: string
{
    case Requested = 'REQUESTED';
    case Verified = 'VERIFIED';
    case Purchased = 'PURCHASED';
    case Owned = 'OWNED';
    case Possessed = 'POSSESSED';
    case Sold = 'SOLD';
    case Settled = 'SETTLED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Request',
            self::Verified => 'Supplier / asset verified',
            self::Purchased => 'Asset purchased',
            self::Owned => 'Ownership acquired',
            self::Possessed => 'Possession (qabd)',
            self::Sold => 'Murabaha sale',
            self::Settled => 'Settled',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
