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
    case MurabahaInventory = 'MURABAHA_INVENTORY';
    case MurabahaReceivable = 'MURABAHA_RECEIVABLE';
    case MurabahaSaleProfit = 'MURABAHA_SALE_PROFIT';
    case CapitalDeployed = 'CAPITAL_DEPLOYED';   // LEGACY: gross-up pair account of the earlier funding design; no new postings
    case CustodyCash = 'CUSTODY_CASH';
    case VentureCapital = 'VENTURE_CAPITAL';
    case BusinessCapital = 'BUSINESS_CAPITAL';

    public function label(): string
    {
        return match ($this) {
            self::InvestorAvailable => 'Investor available',
            self::InvestorInvested => 'Investor invested',
            self::InvestorPending => 'Investor pending',
            self::ProjectFunds => 'Project funds',
            self::BusinessFunds => 'Business funds',
            self::PlatformCash => 'Platform own funds',
            self::PlatformFees => 'Platform fees',
            self::Clearing => 'Clearing',
            self::MurabahaInventory => 'Murabaha inventory (owned asset)',
            self::MurabahaReceivable => 'Murabaha receivable',
            self::MurabahaSaleProfit => 'Murabaha sale profit',
            self::CapitalDeployed => 'Capital deployed (legacy)',
            self::CustodyCash => 'Client custody cash',
            self::VentureCapital => 'Venture capital (at cost)',
            self::BusinessCapital => 'Business partner capital',
        };
    }

    /**
     * The side that increases the account. Asset-like system accounts are debit-normal; wallet,
     * project, income and clearing accounts are credit-normal.
     */
    public function normalSide(): string
    {
        return match ($this) {
            self::PlatformCash, self::CustodyCash, self::VentureCapital, self::MurabahaInventory, self::MurabahaReceivable, self::CapitalDeployed => 'DEBIT',
            default => 'CREDIT',
        };
    }

    /** Retired from new flows; kept so historical entries stay readable. */
    public function isLegacy(): bool
    {
        return $this === self::CapitalDeployed;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
