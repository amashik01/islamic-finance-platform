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

    /**
     * The specific acts a Wakil in this role may be authorised to perform. Selling or consuming the goods is never
     * delegable (rule MUR-AGENT-NO-DISPOSAL), so no such act exists.
     *
     * @return array<string, string> act => description
     */
    public function acts(): array
    {
        return match ($this) {
            self::Purchase => ['place_purchase_order' => 'Place the purchase order with the supplier', 'pay_supplier' => 'Pay the supplier against the invoice'],
            self::AssetAcquisition => ['accept_invoice' => 'Accept the supplier invoice and specification', 'record_title' => 'Record ownership / title evidence'],
            self::DeliveryQabd => ['take_delivery' => 'Take delivery of the goods', 'inspect_goods' => 'Inspect the goods and record possession'],
        };
    }

    /** @return list<WakalahPrincipal> */
    public function allowedPrincipals(): array
    {
        return [WakalahPrincipal::Platform, WakalahPrincipal::Business];
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
