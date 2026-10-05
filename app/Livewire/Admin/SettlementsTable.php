<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\Builder;

class SettlementsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Settlements';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('settlements.view'), 403);
    }

    protected function query(): Builder
    {
        return Settlement::query()->with(['contract', 'project']);
    }

    protected function searchable(): array
    {
        return ['reference'];
    }

    protected function emptyTitle(): string
    {
        return 'No settlements yet.';
    }

    protected function columns(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'sortable' => true, 'render' => fn ($s) => $s->reference],
            'contract' => ['label' => 'Contract', 'render' => fn ($s) => $s->contract->contract_number],
            'project' => ['label' => 'Project', 'render' => fn ($s) => $s->project->title],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($s) => self::badge($s->status)],
            'posted_at' => ['label' => 'Posted', 'sortable' => true, 'render' => fn ($s) => $s->posted_at?->format('d M Y') ?: '—'],
        ];
    }
}
