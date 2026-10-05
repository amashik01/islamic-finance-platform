<?php

namespace App\Livewire\Admin;

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Livewire\Tables\DataTable;
use App\Models\Project;
use App\Services\Project\ProjectWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ProjectsTable extends DataTable
{
    /** Preset status group from the sidebar (e.g. pending review). */
    public string $preset = '';

    protected function heading(): string
    {
        return $this->preset === 'review' ? 'Projects pending review' : 'All projects';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('projects.view'), 403);
    }

    protected function query(): Builder
    {
        return Project::query()->with(['business', 'shariahReviews'])
            ->when($this->preset === 'review', fn ($q) => $q->where('status', ProjectStatus::Review));
    }

    protected function searchable(): array
    {
        return ['title', 'business.name'];
    }

    protected function filters(): array
    {
        return [
            'status' => ['label' => 'Status', 'options' => ProjectStatus::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)],
            'contract' => ['label' => 'Contract', 'options' => ContractType::options(), 'apply' => fn ($q, $v) => $q->where('contract_type', $v)],
            'range' => ['label' => 'Created', 'options' => ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'], 'apply' => fn ($q, $v) => $q->where('created_at', '>=', now()->subDays((int) $v))],
        ];
    }

    protected function columns(): array
    {
        return [
            'title' => ['label' => 'Project', 'sortable' => true, 'render' => fn ($p) => $p->title],
            'business' => ['label' => 'Business', 'render' => fn ($p) => $p->business->name],
            'contract_type' => ['label' => 'Contract', 'sortable' => true, 'render' => fn ($p) => $p->contract_type->label()],
            'funding_target' => ['label' => 'Funding', 'sortable' => true, 'render' => fn ($p) => self::money($p->funded_amount).' / '.self::money($p->funding_target)],
            'shariah' => ['label' => 'Shariah', 'render' => fn ($p) => ($r = $p->shariahReviews->sortByDesc('id')->first()) ? self::badge($r->status) : '—'],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($p) => self::badge($p->status)],
            'created_at' => ['label' => 'Submitted', 'sortable' => true, 'render' => fn ($p) => $p->created_at->format('d M Y')],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.project-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        $project = Project::findOrFail($id);
        $user = auth()->user();
        $wf = app(ProjectWorkflow::class);
        match ($action) {
            'approve' => [$this->authorizeFor('approve', $project), $wf->approve($project, $user, $reason)],
            'reject' => [$this->authorizeFor('reject', $project), $wf->reject($project, $user, $reason)],
            'revision' => [$this->authorizeFor('review', $project), $wf->requestRevision($project, $user, $reason)],
            'publish' => [$this->authorizeFor('approve', $project), $wf->publish($project, $user)],
            'pause' => [$this->authorizeFor('approve', $project), $wf->pause($project, $user, $reason)],
            'resume' => [$this->authorizeFor('approve', $project), $wf->resume($project, $user)],
            'cancel' => [$this->authorizeFor('approve', $project), $wf->cancel($project, $user, $reason)],
        };
    }

    private function authorizeFor(string $ability, Project $project): void
    {
        $this->authorize($ability, $project);
    }
}
