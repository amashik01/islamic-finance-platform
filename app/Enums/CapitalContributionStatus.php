<?php

namespace App\Enums;

enum CapitalContributionStatus: string
{
    case Received = 'RECEIVED';
    case Settled = 'SETTLED';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Settled => 'Settled',
        };
    }
}
