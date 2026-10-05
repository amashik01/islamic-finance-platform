<?php

namespace App\Services\Contract;

use App\Enums\CapitalContributionStatus;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Notify\Notifier;
use App\Support\Money\Currency;

/**
 * The single authority that moves a Mudarabah / Musharakah project and its contract from APPROVED/FUNDING to ACTIVE.
 *
 * Activation rule: the contract becomes ACTIVE, atomically with the project, at the moment the required capital is
 * fully received —
 *   - Mudarabah:  the investor funding target has been reached;
 *   - Musharakah: the investor funding target has been reached AND the business's capital contribution has been
 *                 received and recorded (whichever of the two happens last triggers activation).
 * It is never triggered by publication, by an investment merely existing, or by a status edit.
 *
 * Callers must already be inside a database transaction holding the project row lock; the contract row is locked here
 * (lock order everywhere: project -> contract -> investments -> ledger accounts).
 */
class ContractLifecycle
{
    public function __construct(private AuditLogger $audit, private Notifier $notify) {}

    /** @return bool true when this call performed the activation */
    public function activateIfFunded(Project $project): bool
    {
        Currency::require($project->currency, 'The project');
        $fullyFunded = $project->funding_target > 0 && $project->funded_amount >= $project->funding_target;
        if (! $fullyFunded) {
            return false;   // partial funding never activates anything
        }

        $contract = Contract::where('project_id', $project->id)->lockForUpdate()->first();

        if (! $contract) {
            // Legacy/contract-less project: only the project can be activated.
            return $this->activateProject($project);
        }
        Currency::require($contract->currency, 'The contract');

        if ($contract->status === ContractStatus::Active) {
            $this->activateProject($project);   // idempotent: never a second activation

            return false;
        }
        if ($contract->status !== ContractStatus::Approved) {
            throw new FinancialException('This project cannot be activated because its contract is '.strtolower($contract->status->label()).'.');
        }
        if (! in_array($project->status, [ProjectStatus::Funding, ProjectStatus::Active], true)) {
            throw new FinancialException('This project is not eligible for activation in its current status.');
        }

        $review = $project->shariahReviews()->latest('id')->first();
        if (! $review || $review->status !== ShariahReviewStatus::Approved) {
            throw new FinancialException('A Shariah review approval is required before a contract can become active.');
        }

        if ($contract->contract_type === ContractType::Musharakah && ! $this->businessCapitalReceived($contract)) {
            return false;   // investors are fully funded; the partnership starts when the business capital arrives
        }

        $contract->transitionTo(ContractStatus::Active);
        $contract->forceFill(['start_date' => now()->toDateString()])->save();
        $this->activateProject($project);
        $this->audit->record('contract.activated', $contract, ['status' => ContractStatus::Approved->value], ['status' => ContractStatus::Active->value, 'project_id' => $project->id]);
        $this->notify->to($project->business->user, 'Contract active', $project->title.' is fully funded and its contract is now active.', 'success', route('business.projects.show', $project));

        return true;
    }

    private function activateProject(Project $project): bool
    {
        if ($project->status === ProjectStatus::Active) {
            return false;
        }
        if ($project->status !== ProjectStatus::Funding) {
            throw new FinancialException('This project is not eligible for activation in its current status.');
        }
        $project->forceFill(['status' => ProjectStatus::Active])->save();

        return true;
    }

    /** A Musharakah contribution counts only if it was financially recorded (ledger transaction) in the agreed amount. */
    public function businessCapitalReceived(Contract $contract): bool
    {
        $terms = $contract->musharakah;
        $c = $contract->musharakahContribution;

        return $terms && $c && $c->transaction_id !== null && $c->amount === (int) $terms->business_contribution
            && in_array($c->status, [CapitalContributionStatus::Received, CapitalContributionStatus::Settled], true);
    }
}
