<?php

namespace App\Services\Finance;

use App\Enums\LossAllocationBasis;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Musharakah: both parties contribute capital. Ownership follows capital; the profit ratio is
 * agreed separately and is NOT assumed equal to ownership. Loss follows the contract's basis.
 */
final class MusharakahProfitCalculator
{
    /** @return array{total: Money, investor_ownership_bps: int, business_ownership_bps: int} */
    public function ownership(Money $investor, Money $business): array
    {
        if (! $investor->isPositive() || $business->isNegative()) {
            throw new InvalidArgumentException('Contributions must be positive.');
        }
        $total = $investor->add($business);
        $iBps = intdiv($investor->minor * Money::BPS + intdiv($total->minor, 2), $total->minor);

        return ['total' => $total, 'investor_ownership_bps' => $iBps, 'business_ownership_bps' => Money::BPS - $iBps];
    }

    /**
     * @return array{investor_profit: Money, business_profit: Money, investor_loss: Money, business_loss: Money, investor_return: Money, business_return: Money}
     */
    public function settle(
        Money $investorCapital,
        Money $businessCapital,
        Money $netResult,
        int $investorProfitBps,
        int $businessProfitBps,
        LossAllocationBasis $lossBasis = LossAllocationBasis::CapitalRatio,
    ): array {
        if ($investorProfitBps + $businessProfitBps !== Money::BPS || $investorProfitBps < 0 || $businessProfitBps < 0) {
            throw new InvalidArgumentException('Profit ratios must total 100%.');
        }
        $zero = Money::zero($investorCapital->currency);

        if ($netResult->isPositive()) {
            [$ip, $bp] = $netResult->allocate([$investorProfitBps, $businessProfitBps]);
            $iLoss = $bLoss = $zero;
        } else {
            $ip = $bp = $zero;
            $weights = $lossBasis === LossAllocationBasis::CapitalRatio
                ? [$investorCapital->minor, $businessCapital->minor]
                : [$investorProfitBps, $businessProfitBps];
            [$iLoss, $bLoss] = $netResult->negate()->allocate($weights);
        }

        return [
            'investor_profit' => $ip,
            'business_profit' => $bp,
            'investor_loss' => $iLoss,
            'business_loss' => $bLoss,
            'investor_return' => $investorCapital->subtract($iLoss)->add($ip),
            'business_return' => $businessCapital->subtract($bLoss)->add($bp),
        ];
    }
}
