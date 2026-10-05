<?php

namespace App\Enums;

enum SettlementItemType: string
{
    case Principal = 'PRINCIPAL';
    case InvestmentProfit = 'INVESTMENT_PROFIT';
    case MurabahaSaleProfit = 'MURABAHA_SALE_PROFIT';
    case Fee = 'FEE';
    case Adjustment = 'ADJUSTMENT';
    case BusinessProfitShare = 'BUSINESS_PROFIT_SHARE';
    case ManagerLiability = 'MANAGER_LIABILITY';
    case BusinessCapitalReturn = 'BUSINESS_CAPITAL_RETURN';
    case BusinessCapitalLoss = 'BUSINESS_CAPITAL_LOSS';

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'Principal',
            self::InvestmentProfit => 'Investment profit',
            self::MurabahaSaleProfit => 'Murabaha sale profit',
            self::Fee => 'Fees',
            self::Adjustment => 'Adjustments',
            self::BusinessProfitShare => 'Business profit share',
            self::ManagerLiability => 'Manager liability (recoverable)',
            self::BusinessCapitalReturn => 'Business capital return',
            self::BusinessCapitalLoss => 'Business capital loss',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
