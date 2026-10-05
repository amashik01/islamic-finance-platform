<?php

namespace App\Enums;

/** Selecting a Wakil is not the same as an approved Wakalah: an appointment is only confirmed by the Shariah review step. */
enum WakalahStatus: string
{
    case Proposed = 'PROPOSED';
    case Confirmed = 'CONFIRMED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed — pending Shariah review',
            self::Confirmed => 'Confirmed after Shariah review',
            self::Revoked => 'Revoked',
        };
    }
}
