<?php

namespace App\Enums;

/** Selecting a Wakil is not the same as an approved Wakalah: an appointment is only confirmed by the Shariah review step. */
enum WakalahStatus: string
{
    case Proposed = 'PROPOSED';                                   // selected; also "revision requested" after a review
    case PendingWakilAcceptance = 'PENDING_WAKIL_ACCEPTANCE';
    case PendingShariahReview = 'PENDING_SHARIAH_REVIEW';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::PendingWakilAcceptance => 'Awaiting the Wakil\'s acceptance',
            self::PendingShariahReview => 'Awaiting Shariah review of the Wakalah',
            self::Confirmed => 'Confirmed after the Shariah review recorded for this Wakalah',
            self::Rejected => 'Rejected',
            self::Revoked => 'Revoked',
        };
    }

    /** A live appointment that has not completed acceptance and review. */
    public function isPending(): bool
    {
        return in_array($this, [self::Proposed, self::PendingWakilAcceptance, self::PendingShariahReview], true);
    }
}
