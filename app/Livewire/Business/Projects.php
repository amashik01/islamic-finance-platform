<?php

namespace App\Livewire\Business;

use App\Enums\ProjectStatus;
use App\Livewire\Tables\DataTable;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

class Projects extends DataTable
{
    protected string $layout = 'components.business-layout';

    protected function heading(): string
    {
        return 'My projects';
    }

    protected function query(): Builder
    {
        return Project::query()->where('business_id', auth()->user()->business->id);
    }

    protected function searchable(): array
    {
        return ['title'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => ProjectStatus::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return "You haven't submitted a project yet. Create a project to start raising capital.";
    }

    protected function columns(): array
    {
        return [
            'title' => ['label' => 'Project', 'sortable' => true, 'render' => fn ($p) => $p->title],
            'contract_type' => ['label' => 'Contract', 'sortable' => true, 'render' => fn ($p) => $p->contract_type->label()],
            'funding' => ['label' => 'Funded', 'render' => fn ($p) => self::money($p->funded_amount).' / '.self::money($p->funding_target)],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($p) => self::badge($p->status)],
            'updated_at' => ['label' => 'Updated', 'sortable' => true, 'render' => fn ($p) => $p->updated_at->diffForHumans()],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.business.partials.project-actions';
    }
}
