<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Pending = 'PENDING';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED';
    case Processing = 'PROCESSING';
    case Paid = 'PAID';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
