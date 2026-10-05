<?php

namespace App\Livewire\Investor;

use App\Enums\InvestmentStatus;
use App\Livewire\Tables\DataTable;
use App\Models\Investment;
use Illuminate\Database\Eloquent\Builder;

class Investments extends DataTable
{
    protected string $layout = 'components.investor-layout';

    protected function heading(): string
    {
        return 'My investments';
    }

    protected function query(): Builder
    {
        // Scoped to the signed-in investor: other investors' rows are never queried.
        return Investment::query()->with('project.business')->where('investor_id', auth()->user()->investor->id);
    }

    protected function searchable(): array
    {
        return ['project.title'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => InvestmentStatus::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return 'No active investments yet. Explore approved opportunities to start building your portfolio.';
    }

    protected function columns(): array
    {
        return [
            'project' => ['label' => 'Project', 'render' => fn ($i) => $i->project->title],
            'contract' => ['label' => 'Contract', 'render' => fn ($i) => $i->project->contract_type->label()],
            'amount' => ['label' => 'Invested', 'sortable' => true, 'render' => fn ($i) => self::money($i->amount, $i->currency)],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($i) => self::badge($i->status)],
            'maturity_date' => ['label' => 'Maturity', 'sortable' => true, 'render' => fn ($i) => $i->maturity_date?->format('d M Y') ?: '—'],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.investor.partials.investment-actions';
    }
}
