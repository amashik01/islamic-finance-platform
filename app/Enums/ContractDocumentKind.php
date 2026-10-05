<?php

namespace App\Enums;

enum ContractDocumentKind: string
{
    case MasterAqd = 'MASTER_AQD';
    case Participation = 'PARTICIPATION';
    case Wakalah = 'WAKALAH';
    case MurabahaSale = 'MURABAHA_SALE';

    public function label(): string
    {
        return match ($this) {
            self::MasterAqd => 'Aqd (master agreement)',
            self::Participation => 'Participation agreement',
            self::Wakalah => 'Wakalah appointment',
            self::MurabahaSale => 'Murabaha sale agreement',
        };
    }
}
