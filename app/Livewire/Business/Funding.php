<?php

namespace App\Livewire\Business;

use App\Enums\InvestmentStatus;
use App\Livewire\Tables\DataTable;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/** Per-project funding progress. Investor identities are never shown to the business. */
class Funding extends DataTable
{
    protected string $layout = 'components.business-layout';

    protected function heading(): string
    {
        return 'Funding received';
    }

    protected function query(): Builder
    {
        return Project::query()->where('business_id', auth()->user()->business->id)->withCount(['investments as investor_count' => fn ($q) => $q->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active, InvestmentStatus::Completed])]);
    }

    protected function emptyTitle(): string
    {
        return 'No funding yet. Funding appears here once a published project receives investments.';
    }

    protected function columns(): array
    {
        return [
            'title' => ['label' => 'Project', 'sortable' => true, 'render' => fn ($p) => $p->title],
            'contract_type' => ['label' => 'Contract', 'render' => fn ($p) => $p->contract_type->label()],
            'funded_amount' => ['label' => 'Received', 'sortable' => true, 'render' => fn ($p) => self::money($p->funded_amount)],
            'funding_target' => ['label' => 'Target', 'sortable' => true, 'render' => fn ($p) => self::money($p->funding_target)],
            'progress' => ['label' => 'Progress', 'render' => fn ($p) => $p->fundingPercent().'%'],
            'investors' => ['label' => 'Investors', 'render' => fn ($p) => $p->investor_count],
            'status' => ['label' => 'Status', 'render' => fn ($p) => self::badge($p->status)],
        ];
    }
}
