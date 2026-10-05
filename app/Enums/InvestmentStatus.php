<?php

namespace App\Enums;

enum InvestmentStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Refunded = 'REFUNDED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
