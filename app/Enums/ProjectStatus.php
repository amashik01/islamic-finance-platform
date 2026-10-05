<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'DRAFT';
    case Review = 'REVIEW';
    case NeedsRevision = 'NEEDS_REVISION';
    case Approved = 'APPROVED';
    case Funding = 'FUNDING';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Defaulted = 'DEFAULTED';
    case Rejected = 'REJECTED';
    case Paused = 'PAUSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Review => 'Under review',
            self::NeedsRevision => 'Needs revision',
            self::Approved => 'Approved',
            self::Funding => 'Funding',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Defaulted => 'Defaulted',
            self::Rejected => 'Rejected',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
