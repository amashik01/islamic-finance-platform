<?php

namespace App\Domain\Aqd;

use App\Enums\ContractType;

final class AqdRegistry
{
    public static function for(ContractType $type): AqdDefinition
    {
        return match ($type) {
            ContractType::Mudarabah => new MudarabahAqd,
            ContractType::Musharakah => new MusharakahAqd,
            ContractType::Murabaha => new MurabahaAqd,
        };
    }
}
