<?php

namespace App\Services\Finance;

use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Mudarabah: investor supplies capital, manager supplies work. Actual profit is shared
 * by the agreed ratio. A financial loss falls on the capital provider; the manager bears
 * liability only for negligence, misconduct or breach (recorded as a recoverable amount).
 */
final class MudarabahProfitCalculator
{
    /**
     * @return array{investor_profit: Money, business_profit: Money, principal_returned: Money, investor_loss: Money, manager_liability: Money}
     */
    public function settle(
        Money $capital,
        Money $netResult,
        int $investorBps,
        int $businessBps,
        bool $managerAtFault = false,
    ): array {
        $this->assertRatios($investorBps, $businessBps);
        $zero = Money::zero($capital->currency);

        if ($netResult->isPositive()) {
            [$investor, $business] = $netResult->allocate([$investorBps, $businessBps]);

            return [
                'investor_profit' => $investor,
                'business_profit' => $business,
                'principal_returned' => $capital,
                'investor_loss' => $zero,
                'manager_liability' => $zero,
            ];
        }

        $loss = $netResult->negate();
        if ($loss->minor > $capital->minor) {
            throw new InvalidArgumentException('Loss cannot exceed the capital provided.');
        }

        return [
            'investor_profit' => $zero,
            'business_profit' => $zero,
            'principal_returned' => $capital->subtract($loss),
            // Where the manager is at fault the loss is recoverable from the manager, not a plain capital loss.
            'investor_loss' => $managerAtFault ? $zero : $loss,
            'manager_liability' => $managerAtFault ? $loss : $zero,
        ];
    }

    public function assertRatios(int $investorBps, int $businessBps): void
    {
        if ($investorBps <= 0 || $businessBps <= 0 || $investorBps + $businessBps !== Money::BPS) {
            throw new InvalidArgumentException('Investor and business profit ratios must be positive and total 100%.');
        }
    }
}
