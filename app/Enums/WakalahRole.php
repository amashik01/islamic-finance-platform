<?php

namespace App\Enums;

/**
 * What a Wakil is appointed to do. Legally distinct activities are never merged into one generic role.
 * Only Murabaha defines Wakalah roles today; Mudarabah and Musharakah define none (see forContract()).
 */
enum WakalahRole: string
{
    case Purchase = 'PURCHASE';
    case AssetAcquisition = 'ASSET_ACQUISITION';
    case DeliveryQabd = 'DELIVERY_QABD';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Wakil for Purchase',
            self::AssetAcquisition => 'Wakil for Asset Acquisition',
            self::DeliveryQabd => 'Wakil for Delivery / Qabd',
        };
    }

    /** @return list<self> the roles valid for a contract type */
    public static function forContract(ContractType $type): array
    {
        return match ($type) {
            ContractType::Murabaha => [self::Purchase, self::AssetAcquisition, self::DeliveryQabd],
            ContractType::Mudarabah, ContractType::Musharakah => [],
        };
    }

    /** @return array<string, string> value => label for a contract type */
    public static function optionsFor(ContractType $type): array
    {
        return collect(self::forContract($type))->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
