<?php

namespace App\Enums;

enum ShariahReviewStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case Pending = 'PENDING';   // legacy synonym of Submitted
    case Superseded = 'SUPERSEDED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case NeedsRevision = 'NEEDS_REVISION';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted for review',
            self::UnderReview => 'Under review',
            self::Superseded => 'Superseded',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NeedsRevision => 'Needs revision',
        };
    }

    /** A review that is still waiting for, or in the middle of, a decision. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::UnderReview, self::Pending], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
