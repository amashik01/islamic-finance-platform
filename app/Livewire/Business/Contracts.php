<?php

namespace App\Livewire\Business;

use App\Livewire\Tables\DataTable;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

class Contracts extends DataTable
{
    protected string $layout = 'components.business-layout';

    protected function heading(): string
    {
        return 'My contracts';
    }

    protected function query(): Builder
    {
        return Contract::query()->with('project')->whereHas('project', fn ($q) => $q->where('business_id', auth()->user()->business->id));
    }

    protected function emptyTitle(): string
    {
        return 'No contracts yet. A contract is created with each project you submit.';
    }

    protected function columns(): array
    {
        return [
            'contract_number' => ['label' => 'Contract', 'sortable' => true, 'render' => fn ($c) => $c->contract_number],
            'project' => ['label' => 'Project', 'render' => fn ($c) => $c->project->title],
            'contract_type' => ['label' => 'Type', 'render' => fn ($c) => $c->contract_type->label()],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($c) => self::badge($c->status)],
            'end_date' => ['label' => 'Ends', 'sortable' => true, 'render' => fn ($c) => $c->end_date?->format('d M Y') ?: '—'],
        ];
    }
}
