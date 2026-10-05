<?php

namespace App\Services\Finance;

use App\Enums\SettlementItemType;
use App\Support\Money\Money;

/** Builds distinct settlement lines; principal, profit, sale profit, fees and adjustments never mix. */
final class SettlementCalculator
{
    /**
     * @param  array{principal?: Money, investment_profit?: Money, murabaha_sale_profit?: Money, fee?: Money, adjustment?: Money}  $parts
     * @return array{items: list<array{type: SettlementItemType, amount: Money}>, net_to_investor: Money}
     */
    public function build(array $parts, string $currency): array
    {
        $map = [
            'principal' => SettlementItemType::Principal,
            'investment_profit' => SettlementItemType::InvestmentProfit,
            'murabaha_sale_profit' => SettlementItemType::MurabahaSaleProfit,
            'fee' => SettlementItemType::Fee,
            'adjustment' => SettlementItemType::Adjustment,
        ];
        $items = [];
        $net = Money::zero($currency);
        foreach ($map as $key => $type) {
            $amount = $parts[$key] ?? null;
            if ($amount === null || $amount->isZero()) {
                continue;
            }
            $items[] = ['type' => $type, 'amount' => $amount];
            // Fees are deductions; adjustments carry their own sign.
            $net = $type === SettlementItemType::Fee ? $net->subtract($amount) : $net->add($amount);
        }

        return ['items' => $items, 'net_to_investor' => $net];
    }
}
