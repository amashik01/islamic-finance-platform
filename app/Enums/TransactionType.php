<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'DEPOSIT';
    case Investment = 'INVESTMENT';
    case PrincipalReturn = 'PRINCIPAL_RETURN';
    case ProfitDistribution = 'PROFIT_DISTRIBUTION';
    case Withdrawal = 'WITHDRAWAL';
    case Refund = 'REFUND';
    case Adjustment = 'ADJUSTMENT';
    case MurabahaPayment = 'MURABAHA_PAYMENT';
    case Reversal = 'REVERSAL';
    case MurabahaPurchase = 'MURABAHA_PURCHASE';
    case MurabahaSale = 'MURABAHA_SALE';
    case ProjectFunding = 'PROJECT_FUNDING';
    case CapitalRelease = 'CAPITAL_RELEASE';
    case MusharakahCapital = 'MUSHARAKAH_CAPITAL';
    case BusinessRemittance = 'BUSINESS_REMITTANCE';
    case BusinessCapitalReturn = 'BUSINESS_CAPITAL_RETURN';
    case CapitalLoss = 'CAPITAL_LOSS';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Investment => 'Investment',
            self::PrincipalReturn => 'Principal return',
            self::ProfitDistribution => 'Profit distribution',
            self::Withdrawal => 'Withdrawal',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
            self::MurabahaPayment => 'Murabaha payment',
            self::Reversal => 'Reversal',
            self::MurabahaPurchase => 'Murabaha asset purchase',
            self::MurabahaSale => 'Murabaha sale',
            self::ProjectFunding => 'Project funding',
            self::CapitalRelease => 'Project capital release',
            self::MusharakahCapital => 'Musharakah business capital',
            self::BusinessRemittance => 'Business remittance',
            self::BusinessCapitalReturn => 'Business capital return',
            self::CapitalLoss => 'Business capital loss',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
