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
    case CapitalDeployment = 'CAPITAL_DEPLOYMENT';
    case VentureCapitalReturn = 'VENTURE_CAPITAL_RETURN';
    case InterimProceeds = 'INTERIM_PROCEEDS';
    case LossRecognition = 'LOSS_RECOGNITION';
    case RecoveryReceipt = 'RECOVERY_RECEIPT';
    case RecoveryDistribution = 'RECOVERY_DISTRIBUTION';

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
            self::CapitalLoss => 'Business capital loss (legacy)',
            self::CapitalDeployment => 'Capital deployed to the venture',
            self::VentureCapitalReturn => 'Venture capital returned',
            self::InterimProceeds => 'Interim proceeds received',
            self::LossRecognition => 'Capital loss recognised',
            self::RecoveryReceipt => 'Recovery received',
            self::RecoveryDistribution => 'Recovery distributed',
        };
    }

    /** Retired from new flows (earlier funding/remittance design); kept for historical entries. */
    public function isLegacy(): bool
    {
        return in_array($this, [self::ProjectFunding, self::CapitalRelease, self::BusinessRemittance, self::CapitalLoss], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
