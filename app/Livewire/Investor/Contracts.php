<?php

namespace App\Livewire\Investor;

use App\Livewire\Tables\DataTable;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

class Contracts extends DataTable
{
    protected string $layout = 'components.investor-layout';

    protected function heading(): string
    {
        return 'My contracts';
    }

    protected function query(): Builder
    {
        $id = auth()->user()->investor->id;

        return Contract::query()->with('project.business')->whereHas('investments', fn ($q) => $q->where('investor_id', $id));
    }

    protected function emptyTitle(): string
    {
        return 'You have no contracts yet. A contract is issued for each project you invest in.';
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
