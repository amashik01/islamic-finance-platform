<?php

namespace App\Services\Aqd;

use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ContractStatus;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\ContractDocument;
use App\Models\Investor;
use App\Models\Project;
use App\Services\Settings\SettingsService;
use App\Services\Wakalah\WakalahService;
use App\Support\Money\Money;

/**
 * The financial activation gate. Money moves only when every contractual and Shariah precondition holds, and this is
 * checked on the server inside the investment transaction. Selecting a form, a Wakil or a template moves nothing.
 *
 *   project approved + funding  AND  aqd approved  AND  Shariah review approved for THIS structure and template version
 *   AND  required Wakalah confirmed  AND  the project's agreement executed (hash intact)  AND  the investor's
 *   participation agreement executed for this exact amount  AND  investor identity verified  AND  platform role approved
 */
class AqdGate
{
    public function __construct(private WakalahService $wakalah, private SettingsService $settings) {}

    /** Everything about the PROJECT that must hold before it can be published or invested in. Returns the executed master agreement. */
    public function assertProjectContractReady(Project $project, bool $forPublish = false): ContractDocument
    {
        $contract = $project->contract ?? throw new FinancialException('The project has no contract.');
        if (! $forPublish && $project->status !== ProjectStatus::Funding) {
            throw new FinancialException('This investment is no longer accepting funds.');
        }
        if (! in_array($contract->status, [ContractStatus::Approved, ContractStatus::Active], true)) {
            throw new FinancialException('The contract has not been approved.');
        }
        $review = $project->shariahReviews()->latest('id')->first();
        if (! $review || $review->status !== ShariahReviewStatus::Approved) {
            throw new FinancialException('A Shariah review approval is required.');
        }
        if ($review->aqd_type !== $project->contract_type->value || $review->reviewed_terms_hash === null) {
            throw new FinancialException('The Shariah review does not cover this aqd type and its recorded terms.');
        }
        if ($this->wakalah->hasUnconfirmed($project)) {
            throw new FinancialException('A Wakalah appointment is not yet confirmed: the Wakil must accept it and a Shariah reviewer must review it (a project-level approval does not confirm a Wakalah).');
        }
        $master = ContractDocument::where('contract_id', $contract->id)->where('kind', K::MasterAqd->value)->where('status', S::Executed->value)->latest('id')->first();
        if (! $master) {
            throw new FinancialException('The project\'s agreement (Aqd) has not been executed: the business must sign it first.');
        }
        if (! $master->hashIntact()) {
            throw new FinancialException('The executed agreement does not match its recorded hash.');
        }
        if ($master->terms_hash !== $review->reviewed_terms_hash) {
            throw new FinancialException('The executed agreement is not the one the Shariah reviewer approved.');
        }
        if ($master->templateVersion->shariah_review_status !== 'APPROVED') {
            throw new FinancialException('The template version of the agreement has no Shariah reviewer\'s approval.');
        }
        if (! $master->signatures()->exists()) {
            throw new FinancialException('The executed agreement has no signature on record.');
        }

        return $master;
    }

    /** Platform role: live money needs an approved role; sandbox deployments are exempt and say so. */
    public function assertPlatformRole(): void
    {
        if ($this->settings->get('shariah.platform_role') === 'UNSET' && ! config('finance.sandbox')) {
            throw new FinancialException('The platform\'s contractual role has not been approved by its Shariah board; investments cannot be accepted.');
        }
    }

    /**
     * Resolves and validates the investor's executed participation agreement for this exact amount. Call inside the investment
     * transaction: the row is locked so two requests cannot consume the same agreement.
     */
    public function assertCanInvest(Project $project, Investor $investor, Money $amount, ?ContractDocument $participation): ContractDocument
    {
        $this->assertPlatformRole();
        if ($investor->kyc_status !== KycStatus::Approved) {
            throw new FinancialException('Complete identity verification before investing.');
        }
        $this->assertProjectContractReady($project);

        $q = ContractDocument::where('project_id', $project->id)->where('kind', K::Participation->value)->where('party_user_id', $investor->user_id)
            ->where('status', S::Executed->value)->whereNull('consumed_by_investment_id')->where('amount', $amount->minor);
        $doc = ($participation ? $q->whereKey($participation->id) : $q)->orderBy('id')->lockForUpdate()->first();
        if (! $doc) {
            throw new FinancialException('Sign a participation agreement for exactly '.$amount->format().' before investing.');
        }
        if (! $doc->hashIntact() || $doc->signatures()->where('signer_user_id', $investor->user_id)->doesntExist()) {
            throw new FinancialException('The participation agreement is not intact or is unsigned.');
        }

        return $doc;
    }
}
