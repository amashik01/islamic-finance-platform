<?php

namespace App\Services\Aqd;

use App\Domain\Aqd\AqdRegistry;
use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\ContractAmendment;
use App\Models\ContractDocument;
use App\Models\ShariahReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Amending an executed agreement never edits it. The change is requested, reviewed by a Shariah reviewer (and flagged for
 * legal review once investors are bound), a NEW version is generated and signed, and only then is the old version SUPERSEDED.
 * Scope: the project's master agreement. Participation agreements are not amended; they are replaced by a new agreement.
 */
class ContractAmendmentService
{
    public function __construct(private ContractGenerator $generator, private AuditLogger $audit) {}

    /** @param array<string, mixed> $changes aqd term key => new value */
    public function request(ContractDocument $from, User $by, string $reason, array $changes): ContractAmendment
    {
        if (! $by->can('contracts.manage') && $by->business?->id !== $from->project->business_id) {
            throw new FinancialException('Only the business or staff can request an amendment.');
        }
        if ($from->kind !== K::MasterAqd || $from->status !== S::Executed) {
            throw new FinancialException('Only an executed master agreement can be amended; other agreements are replaced, not amended.');
        }
        if (trim($reason) === '' || $changes === []) {
            throw new FinancialException('State the reason and the exact terms to change.');
        }
        if (ContractAmendment::where('from_document_id', $from->id)->whereIn('status', ['REQUESTED', 'APPROVED'])->exists()) {
            throw new FinancialException('An amendment to this agreement is already in progress.');
        }
        $contract = $from->contract;
        $unknown = array_diff(array_keys($changes), array_keys($contract->aqd_terms ?? []));
        if ($unknown !== []) {
            throw new FinancialException('Unknown term(s): '.implode(', ', $unknown).'.');
        }
        AqdRegistry::for($contract->contract_type)->assertShariahRules(array_merge($contract->aqd_terms ?? [], $changes));
        $a = ContractAmendment::create([
            'from_document_id' => $from->id, 'reason' => $reason, 'changes' => $changes, 'requested_by' => $by->id,
            'legal_review_required' => $contract->investments()->exists(),
        ]);
        $this->audit->record('aqd.amendment_requested', $from->project, null, ['amendment' => $a->id, 'document' => $from->reference, 'terms' => array_keys($changes)], $reason);

        return $a;
    }

    public function review(ContractAmendment $a, User $reviewer, ShariahReviewStatus $status, string $notes, ?string $legalReviewedBy = null): ContractAmendment
    {
        if (! $reviewer->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can review an amendment.');
        }

        return DB::transaction(function () use ($a, $reviewer, $status, $notes, $legalReviewedBy) {
            $a = ContractAmendment::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($a->status !== 'REQUESTED') {
                throw new FinancialException('This amendment has already been decided.');
            }
            $from = $a->fromDocument;
            if ($status !== ShariahReviewStatus::Approved) {
                $a->forceFill(['status' => 'REJECTED', 'shariah_reviewer_id' => $reviewer->id, 'shariah_reviewed_at' => now(), 'shariah_notes' => $notes])->save();
                $this->audit->record('aqd.amendment_rejected', $from->project, null, ['amendment' => $a->id], $notes);

                return $a;
            }
            if ($a->legal_review_required && blank($legalReviewedBy)) {
                throw new FinancialException('Investors are bound by the current agreement: record who completed the legal review before approving the amendment.');
            }
            $project = $from->project;
            $contract = $from->contract;
            // The reviewed terms are the new terms: persisted, then rendered as a draft whose hash the reviewer approves.
            $contract->forceFill(['aqd_terms' => array_merge($contract->aqd_terms ?? [], $a->changes)])->save();
            $review = new ShariahReview(['project_id' => $project->id, 'contract_id' => $contract->id]);
            $prior = (int) $project->shariahReviews()->max('review_version');
            $review->forceFill(['status' => ShariahReviewStatus::UnderReview, 'reviewer_id' => $reviewer->id, 'aqd_type' => $project->contract_type->value, 'review_version' => $prior + 1, 'scope' => 'amendment', 'notes' => $notes])->save();
            $draft = $this->generator->master($project->fresh(), $reviewer);
            $review->forceFill(['status' => ShariahReviewStatus::Approved, 'reviewed_at' => now(), 'reviewed_terms_hash' => $draft->terms_hash, 'template_version_id' => $draft->template_version_id])->save();
            $exec = $this->generator->master($project->fresh(), $reviewer);
            $exec->forceFill(['shariah_review_id' => $review->id, 'supersedes_id' => $from->id])->save();
            $a->forceFill(['status' => 'APPROVED', 'to_document_id' => $exec->id, 'shariah_reviewer_id' => $reviewer->id, 'shariah_reviewed_at' => now(), 'shariah_notes' => $notes, 'legal_reviewed_by' => $legalReviewedBy])->save();
            $this->audit->record('aqd.amended', $project, ['document' => $from->reference], ['document' => $exec->reference, 'amendment' => $a->id], $a->reason);

            return $a;
        });
    }
}
