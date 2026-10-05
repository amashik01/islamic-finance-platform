<?php

namespace App\Services\Project;

use App\Enums\ContractStatus;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus as S;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
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

    public function __construct(private AuditLogger $audit) {}

    public function submit(Project $p, User $by): Project
    {
        if (! $p->contract) {
            throw new FinancialException('Add contract terms before submitting the project.');
        }
        if ($p->business->kyc_status !== KycStatus::Approved) {
            throw new FinancialException('Your business must be verified before submitting a project.');
        }
        $p = $this->move($p, 'submit', $by);
        ShariahReview::firstOrCreate(['project_id' => $p->id, 'status' => ShariahReviewStatus::Pending], ['contract_id' => $p->contract->id]);

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

    public function recordShariahReview(Project $p, User $reviewer, ShariahReviewStatus $status, ?string $notes): ShariahReview
    {
        return DB::transaction(function () use ($p, $reviewer, $status, $notes) {
            $review = $p->shariahReviews()->latest('id')->first() ?? new ShariahReview(['project_id' => $p->id, 'contract_id' => $p->contract?->id]);
            $review->forceFill(['status' => $status, 'reviewer_id' => $reviewer->id, 'notes' => $notes, 'reviewed_at' => now()])->save();
            $this->audit->record('shariah.'.strtolower($status->value), $p, null, ['status' => $status->value], $notes);

            return $review;
        });
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

            return $p->fresh();
        });
    }
}
