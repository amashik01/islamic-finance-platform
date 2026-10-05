<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Paid = 'PAID';
    case Partial = 'PARTIAL';
    case Overdue = 'OVERDUE';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Paid => 'Paid',
            self::Partial => 'Partially paid',
            self::Overdue => 'Overdue',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
