<?php

namespace App\Enums;

enum LedgerAccountType: string
{
    case InvestorAvailable = 'INVESTOR_AVAILABLE';
    case InvestorInvested = 'INVESTOR_INVESTED';
    case InvestorPending = 'INVESTOR_PENDING';
    case ProjectFunds = 'PROJECT_FUNDS';
    case BusinessFunds = 'BUSINESS_FUNDS';
    case PlatformCash = 'PLATFORM_CASH';
    case PlatformFees = 'PLATFORM_FEES';
    case Clearing = 'CLEARING';

    public function label(): string
    {
        return match ($this) {
            self::InvestorAvailable => 'Investor available',
            self::InvestorInvested => 'Investor invested',
            self::InvestorPending => 'Investor pending',
            self::ProjectFunds => 'Project funds',
            self::BusinessFunds => 'Business funds',
            self::PlatformCash => 'Platform cash',
            self::PlatformFees => 'Platform fees',
            self::Clearing => 'Clearing',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
