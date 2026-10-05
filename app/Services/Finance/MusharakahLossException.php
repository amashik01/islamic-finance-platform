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
 * Musharakah losses follow capital contribution. A different (agreed) allocation is an exception that is
 * unavailable by default: it needs a user with Shariah-review permission, a documented reason, can only be
 * set before the contract starts, and is audited.
 */
class MusharakahLossException
{
    public function __construct(private AuditLogger $audit) {}

    public function approve(MusharakahContract $terms, User $approver, string $reason): MusharakahContract
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
        $terms->forceFill(['loss_allocation_basis' => LossAllocationBasis::CapitalRatio, 'loss_exception_reason' => null, 'loss_exception_approved_by' => null, 'loss_exception_approved_at' => null])->save();
        $this->audit->record('musharakah.loss_exception_revoked', $terms->contract, null, ['loss_allocation_basis' => 'CAPITAL_RATIO'], $reason);

        return $terms;
    }
}
