<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'PENDING';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
            self::Failed => 'Failed',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
