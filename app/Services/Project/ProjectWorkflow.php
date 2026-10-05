<?php

namespace App\Services\Project;

use App\Enums\ContractStatus;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus as S;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Enums\ContractDocumentKind;
use App\Enums\ContractDocumentStatus;
use App\Models\ContractDocument;
use App\Models\Project;
use App\Models\ShariahReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Project lifecycle. Every transition is validated server-side and audited. */
class ProjectWorkflow
{
    private const ALLOWED = [
        'submit' => [[S::Draft, S::NeedsRevision], S::Review],
        'requestRevision' => [[S::Review], S::NeedsRevision],
        'approve' => [[S::Review], S::Approved],
        'reject' => [[S::Review, S::Approved], S::Rejected],
        'publish' => [[S::Approved], S::Funding],
        'pause' => [[S::Funding], S::Paused],
        'resume' => [[S::Paused], S::Funding],
        'cancel' => [[S::Draft, S::Review, S::NeedsRevision, S::Approved, S::Funding, S::Paused], S::Cancelled],
    ];

    public function __construct(private AuditLogger $audit, private \App\Services\Notify\Notifier $notify, private \App\Services\Wakalah\WakalahService $wakalah, private \App\Services\Aqd\ContractGenerator $generator, private \App\Services\Aqd\AqdGate $gate) {}

    public function submit(Project $p, User $by): Project
    {
        if (! $p->contract) {
            throw new FinancialException('Add contract terms before submitting the project.');
        }
        if ($p->business->kyc_status !== KycStatus::Approved) {
            throw new FinancialException('Your business must be verified before submitting a project.');
        }
        // The contract-specific terms must be complete: a project without them (or created before the aqd forms) is LEGACY.
        if ($p->contract->aqd_form_version === null) {
            throw new FinancialException('This project predates the contract-specific forms. Open it in the '.$p->contract_type->label().' form and complete its terms before submitting.');
        }
        $missing = \App\Domain\Aqd\AqdRegistry::for($p->contract_type)->missing($p->contract->aqd_terms ?? []);
        if ($missing) {
            throw new FinancialException('Complete the contract terms before submitting: '.implode(', ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ' and '.(count($missing) - 6).' more' : '').'.');
        }
        $p = DB::transaction(function () use ($p, $by) {
            // The reviewer reviews the actual generated agreement: generate it (from the approved terms and a Shariah-approved template) first.
            $draft = $this->generator->master($p, $by);
            $p = $this->move($p, 'submit', $by);
            foreach ($p->shariahReviews()->get()->filter(fn ($r) => $r->status->isOpen()) as $old) {
                $old->forceFill(['status' => ShariahReviewStatus::Superseded])->save();
            }
            $review = new ShariahReview(['project_id' => $p->id, 'contract_id' => $p->contract->id]);
            $review->forceFill([
                'status' => ShariahReviewStatus::Submitted, 'aqd_type' => $p->contract_type->value, 'template_version_id' => $draft->template_version_id,
                'review_version' => 1 + $p->shariahReviews()->count(), 'scope' => 'Project terms and the '.$draft->templateVersion->template->code.' template, version '.$draft->templateVersion->version.' (document '.$draft->reference.')',
            ])->save();
            $draft->forceFill(['shariah_review_id' => $review->id])->save();
            $this->audit->record('aqd.submitted_for_shariah_review', $p, null, ['review_id' => $review->id, 'document' => $draft->reference, 'terms_hash' => $draft->terms_hash]);

            return $p;
        });
        $this->notify->to($p->business->user, 'Project submitted', $p->title.' was submitted for review.', 'info', route('business.projects.show', $p));
        $this->notify->toStaffWith('projects.review', 'New project to review', $p->business->name.' submitted '.$p->title.'.', route('admin.projects.show', $p));

        return $p;
    }

    public function requestRevision(Project $p, User $by, string $reason): Project
    {
        return $this->move($p, 'requestRevision', $by, $reason);
    }

    public function approve(Project $p, User $by, ?string $reason = null): Project
    {
        return $this->move($p, 'approve', $by, $reason, function (Project $p) use ($by) {
            $p->contract->forceFill(['status' => ContractStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now()])->save();
        });
    }

    public function reject(Project $p, User $by, string $reason): Project
    {
        return $this->move($p, 'reject', $by, $reason, fn (Project $p) => $p->contract?->forceFill(['status' => ContractStatus::Cancelled])->save());
    }

    /** Publishing requires an approved Shariah review; the software never self-certifies. */
    public function publish(Project $p, User $by): Project
    {
        $review = $p->shariahReviews()->latest('id')->first();
        if (! $review || $review->status !== ShariahReviewStatus::Approved) {
            throw new FinancialException('A Shariah review approval is required before publishing.');
        }
        $this->gate->assertProjectContractReady($p, true);   // executed agreement, matching review, confirmed Wakalah

        return $this->move($p, 'publish', $by, null, fn (Project $p) => $p->forceFill(['published_at' => now()])->save());
    }

    public function pause(Project $p, User $by, string $reason): Project
    {
        return $this->move($p, 'pause', $by, $reason);
    }

    public function resume(Project $p, User $by): Project
    {
        return $this->move($p, 'resume', $by);
    }

    public function cancel(Project $p, User $by, string $reason): Project
    {
        if ($p->funded_amount > 0) {
            throw new FinancialException('A project that already holds investor funds cannot be cancelled; refund investors first.');
        }

        return $this->move($p, 'cancel', $by, $reason);
    }

    /** Starts a review: the reviewer has opened the submission. */
    public function startShariahReview(Project $p, User $reviewer): ShariahReview
    {
        if (! $reviewer->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can start a Shariah review.');
        }
        $review = $p->shariahReviews()->latest('id')->first();
        if (! $review || ! in_array($review->status, [ShariahReviewStatus::Submitted, ShariahReviewStatus::Pending], true)) {
            throw new FinancialException('There is no submitted review to start.');
        }
        $review->forceFill(['status' => ShariahReviewStatus::UnderReview, 'reviewer_id' => $reviewer->id])->save();
        $this->audit->record('shariah.under_review', $p, null, ['review_id' => $review->id]);

        return $review;
    }

    public function recordShariahReview(Project $p, User $reviewer, ShariahReviewStatus $status, ?string $notes, ?string $conditions = null): ShariahReview
    {
        if (! $reviewer->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can record a Shariah review. A business cannot approve its own project.');
        }

        return DB::transaction(function () use ($p, $reviewer, $status, $notes, $conditions) {
            $review = $p->shariahReviews()->latest('id')->first() ?? new ShariahReview(['project_id' => $p->id, 'contract_id' => $p->contract?->id]);
            $draft = ContractDocument::where('contract_id', $p->contract?->id)->where('kind', ContractDocumentKind::MasterAqd->value)->where('status', ContractDocumentStatus::Draft->value)->latest('id')->first();
            $review->forceFill(['status' => $status, 'reviewer_id' => $reviewer->id, 'notes' => $notes, 'conditions' => $conditions, 'reviewed_at' => now(), 'aqd_type' => $p->contract_type->value]);
            if ($status === ShariahReviewStatus::Approved) {
                if (! $draft || ! $draft->hashIntact()) {
                    throw new FinancialException('There is no intact generated agreement to approve. The project must be submitted for review first.');
                }
                $review->forceFill(['reviewed_terms_hash' => $draft->terms_hash, 'template_version_id' => $draft->template_version_id]);
            }
            $review->save();
            $this->audit->record('shariah.'.strtolower($status->value), $p, null, ['status' => $status->value, 'review_id' => $review->id], $notes);
            if ($status === ShariahReviewStatus::Approved) {
                // The approval is recorded: generate the execution version (same terms, with the review block completed) for signature.
                $exec = $this->generator->master($p->fresh(), $reviewer);
                if ($exec->terms_hash !== $draft->terms_hash) {
                    throw new FinancialException('The terms changed while the agreement was under review; the approval does not apply to the new terms.');
                }
                $exec->forceFill(['shariah_review_id' => $review->id])->save();
                $this->audit->record('aqd.shariah_approved', $p, null, ['review_id' => $review->id, 'document' => $exec->reference, 'terms_hash' => $exec->terms_hash], $notes);
            } elseif ($status === ShariahReviewStatus::Rejected) {
                $this->audit->record('aqd.shariah_rejected', $p, null, ['review_id' => $review->id], $notes);
            } elseif ($status === ShariahReviewStatus::NeedsRevision) {
                $this->audit->record('aqd.revision_requested', $p, null, ['review_id' => $review->id], $notes);
            }

            return $review;
        });
    }

    private function notifyBusiness(Project $p, string $action, ?string $reason): void
    {
        $map = [
            'approve' => ['Project approved', $p->title.' was approved.', 'success'],
            'reject' => ['Project rejected', $p->title.' was rejected.'.($reason ? ' Reason: '.$reason : ''), 'warning'],
            'requestRevision' => ['Revision requested', 'Changes are needed for '.$p->title.'.'.($reason ? ' '.$reason : ''), 'warning'],
            'publish' => ['Project published', $p->title.' is now open for funding.', 'success'],
        ];
        if (isset($map[$action])) {
            [$t, $m, $k] = $map[$action];
            $this->notify->to($p->business->user, $t, $m, $k, route('business.projects.show', $p));
        }
    }

    private function move(Project $project, string $action, User $by, ?string $reason = null, ?\Closure $after = null): Project
    {
        [$from, $to] = self::ALLOWED[$action];

        return DB::transaction(function () use ($project, $action, $by, $reason, $after, $from, $to) {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (! in_array($p->status, $from, true)) {
                throw new FinancialException('This project cannot be modified in its current status.');
            }
            $old = $p->status->value;
            $p->forceFill(['status' => $to, 'reviewer_id' => in_array($action, ['approve', 'reject', 'requestRevision'], true) ? $by->id : $p->reviewer_id])->save();
            $after && $after($p);
            $this->audit->record('project.'.$action, $p, ['status' => $old], ['status' => $to->value], $reason);
            $this->notifyBusiness($p, $action, $reason);

            return $p->fresh();
        });
    }
}
