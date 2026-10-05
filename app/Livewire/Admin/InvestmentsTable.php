<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\Investment;
use Illuminate\Database\Eloquent\Builder;

class InvestmentsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Investments';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('investments.view'), 403);
    }

    protected function query(): Builder
    {
        return Investment::query()->with(['investor.user', 'project']);
    }

    protected function searchable(): array
    {
        return ['project.title', 'idempotency_key'];
    }

    protected function emptyTitle(): string
    {
        return 'No investments yet.';
    }

    protected function columns(): array
    {
        return [
            'investor' => ['label' => 'Investor', 'render' => fn ($i) => $i->investor->user->name],
            'project' => ['label' => 'Project', 'render' => fn ($i) => $i->project->title],
            'contract' => ['label' => 'Contract', 'render' => fn ($i) => $i->project->contract_type->label()],
            'amount' => ['label' => 'Amount', 'sortable' => true, 'render' => fn ($i) => self::money($i->amount, $i->currency)],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($i) => self::badge($i->status)],
            'invested_at' => ['label' => 'Invested', 'sortable' => true, 'render' => fn ($i) => $i->invested_at?->format('d M Y')],
        ];
    }
}
