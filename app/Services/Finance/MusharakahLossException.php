<?php

namespace App\Services\Finance;

use App\Enums\ContractStatus;
use App\Enums\LossAllocationBasis;
use App\Exceptions\FinancialException;
use App\Models\MusharakahContract;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Musharakah losses follow capital contribution (rule MUS-LOSS-CAPITAL).
 *
 * LEGACY / DISABLED FOR NEW AQD. The agreed-loss-ratio exception is FROZEN: the cited standard says the contrary of
 * capital-ratio loss cannot be agreed (rule MUS-LOSS-EXCEPTION-FROZEN, REQUIRES QUALIFIED SHARIAH REVIEW). approve()
 * therefore always refuses. Contracts that already carry the exception are marked legacy_loss_exception and keep their
 * stored terms for settlement and audit; revoke() can still return a not-yet-started contract to the capital ratio.
 */
class MusharakahLossException
{
    public function __construct(private AuditLogger $audit) {}

    public function approve(MusharakahContract $terms, User $approver, string $reason): MusharakahContract
    {
        throw new FinancialException('The agreed loss-ratio exception is frozen for new contracts: Musharakah losses follow capital contribution. Re-enabling it needs a documented ruling by a qualified Shariah board.');
    }

    /** @internal Kept only so the historical approval path stays readable; unreachable while the exception is frozen. */
    private function legacyApprove(MusharakahContract $terms, User $approver, string $reason): MusharakahContract
    {
        if (! $approver->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can approve a loss-allocation exception.');
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw new FinancialException('Document the Shariah basis for this exception (at least 20 characters).');
        }

        return DB::transaction(function () use ($terms, $approver, $reason) {
            $terms = MusharakahContract::whereKey($terms->id)->lockForUpdate()->firstOrFail();
            if (in_array($terms->contract->status, [ContractStatus::Active, ContractStatus::Completed, ContractStatus::Defaulted], true)) {
                throw new FinancialException('The loss allocation cannot be changed once the contract has started.');
            }
            $old = ['loss_allocation_basis' => $terms->loss_allocation_basis->value];
            $terms->forceFill([
                'loss_allocation_basis' => LossAllocationBasis::AgreedRatio, 'loss_exception_reason' => trim($reason),
                'loss_exception_approved_by' => $approver->id, 'loss_exception_approved_at' => now(),
            ])->save();
            $this->audit->record('musharakah.loss_exception_approved', $terms->contract, $old, ['loss_allocation_basis' => 'AGREED_RATIO'], $reason);

            return $terms;
        });
    }

    public function revoke(MusharakahContract $terms, User $by, string $reason): MusharakahContract
    {
        if (in_array($terms->contract->status, [ContractStatus::Active, ContractStatus::Completed, ContractStatus::Defaulted], true)) {
            throw new FinancialException('The loss allocation cannot be changed once the contract has started.');
        }
        $terms->forceFill(['loss_allocation_basis' => LossAllocationBasis::CapitalRatio, 'loss_exception_reason' => null, 'loss_exception_approved_by' => null, 'loss_exception_approved_at' => null])->save();
        $this->audit->record('musharakah.loss_exception_revoked', $terms->contract, null, ['loss_allocation_basis' => 'CAPITAL_RATIO'], $reason);

        return $terms;
    }
}
