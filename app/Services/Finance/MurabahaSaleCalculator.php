<?php

namespace App\Services\Finance;

use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Murabaha is a cost-plus sale of an asset the seller owns and possesses. The mark-up is
 * "Murabaha sale profit" — a sale margin — never interest and never an investment return rate.
 */
final class MurabahaSaleCalculator
{
    public function salePrice(Money $purchaseCost, Money $saleProfit): Money
    {
        if (! $purchaseCost->isPositive() || $saleProfit->isNegative()) {
            throw new InvalidArgumentException('Purchase cost must be positive and sale profit cannot be negative.');
        }

        return $purchaseCost->add($saleProfit);
    }

    public function purchaseCost(Money $unitCost, int $quantity, ?Money $otherCosts = null): Money
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        $total = Money::minor($unitCost->minor * $quantity, $unitCost->currency);

        return $otherCosts ? $total->add($otherCosts) : $total;
    }

    /**
     * Fixed installments of the agreed sale price (the price never grows after sale).
     *
     * @return list<Money>
     */
    public function installments(Money $salePrice, int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('At least one installment is required.');
        }

        return $salePrice->allocate(array_fill(0, $count, 1));
    }

    public function outstanding(Money $salePrice, Money $paid): Money
    {
        $remaining = $salePrice->subtract($paid);
        if ($remaining->isNegative()) {
            throw new InvalidArgumentException('Payments exceed the sale price.');
        }

        return $remaining;
    }
}
