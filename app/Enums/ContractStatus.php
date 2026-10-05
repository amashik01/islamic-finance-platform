<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Draft = 'DRAFT';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Defaulted = 'DEFAULTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Defaulted => 'Defaulted',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Legal transitions. Everything else is refused by Contract::transitionTo(). */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::PendingApproval, self::Approved, self::Cancelled],
            self::PendingApproval => [self::Draft, self::Approved, self::Cancelled],
            self::Approved => [self::Active, self::Cancelled],
            self::Active => [self::Completed, self::Defaulted],
            self::Defaulted => [self::Completed],
            self::Completed, self::Cancelled => [],
        }, true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
