<?php

namespace App\Enums;

enum KycStatus: string
{
    case NotSubmitted = 'NOT_SUBMITTED';
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Not submitted',
            self::Pending => 'Pending review',
            self::Approved => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
