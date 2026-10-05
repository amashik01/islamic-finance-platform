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
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
