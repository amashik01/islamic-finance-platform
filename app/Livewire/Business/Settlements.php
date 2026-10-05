<?php

namespace App\Livewire\Business;

use App\Livewire\Tables\DataTable;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\Builder;

class Settlements extends DataTable
{
    protected string $layout = 'components.business-layout';

    protected function heading(): string
    {
        return 'Settlements';
    }

    protected function query(): Builder
    {
        return Settlement::query()->with(['project', 'contract'])->whereHas('project', fn ($q) => $q->where('business_id', auth()->user()->business->id));
    }

    protected function emptyTitle(): string
    {
        return 'No settlements yet. They are created when a contract is completed.';
    }

    protected function columns(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'sortable' => true, 'render' => fn ($s) => $s->reference],
            'project' => ['label' => 'Project', 'render' => fn ($s) => $s->project->title],
            'contract' => ['label' => 'Contract', 'render' => fn ($s) => $s->contract->contract_type->label()],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($s) => self::badge($s->status)],
            'posted_at' => ['label' => 'Posted', 'sortable' => true, 'render' => fn ($s) => $s->posted_at?->format('d M Y') ?: '—'],
        ];
    }
}
