<?php

namespace App\Livewire\Admin;

use App\Enums\ShariahReviewStatus as R;
use App\Livewire\Tables\DataTable;
use App\Models\ShariahReview;
use App\Services\Project\ProjectWorkflow;
use Illuminate\Database\Eloquent\Builder;

class ShariahReviewsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Shariah reviews';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('shariah.review'), 403);
    }

    protected function query(): Builder
    {
        return ShariahReview::query()->with(['project.business', 'reviewer']);
    }

    protected function searchable(): array
    {
        return ['project.title'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => R::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return 'No projects are awaiting Shariah review.';
    }

    protected function columns(): array
    {
        return [
            'project' => ['label' => 'Project', 'render' => fn ($r) => $r->project->title],
            'contract' => ['label' => 'Contract', 'render' => fn ($r) => $r->project->contract_type->label()],
            'business' => ['label' => 'Business', 'render' => fn ($r) => $r->project->business->name],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($r) => self::badge($r->status)],
            'reviewer' => ['label' => 'Reviewer', 'render' => fn ($r) => $r->reviewer?->name ?? '—'],
            'reviewed_at' => ['label' => 'Reviewed', 'sortable' => true, 'render' => fn ($r) => $r->reviewed_at?->format('d M Y') ?: '—'],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.shariah-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        $review = ShariahReview::with('project')->findOrFail($id);
        $status = match ($action) { 'approve' => R::Approved, 'reject' => R::Rejected, 'revision' => R::NeedsRevision };
        app(ProjectWorkflow::class)->recordShariahReview($review->project, auth()->user(), $status, $reason);
    }
}
