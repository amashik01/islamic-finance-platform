<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
