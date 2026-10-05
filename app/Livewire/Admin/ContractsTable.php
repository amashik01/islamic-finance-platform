<?php

namespace App\Livewire\Admin;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Livewire\Tables\DataTable;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

class ContractsTable extends DataTable
{
    public string $type = 'MUDARABAH';

    protected function heading(): string
    {
        return ContractType::from($this->type)->label().' contracts';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('contracts.view'), 403);
    }

    protected function query(): Builder
    {
        return Contract::query()->with(['project.business'])->where('contract_type', $this->type);
    }

    protected function searchable(): array
    {
        return ['contract_number', 'project.title'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => ContractStatus::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.contract-actions';
    }

    protected function emptyTitle(): string
    {
        return 'No contracts of this type yet.';
    }

    protected function columns(): array
    {
        return [
            'contract_number' => ['label' => 'Contract', 'sortable' => true, 'render' => fn ($c) => $c->contract_number],
            'project' => ['label' => 'Project', 'render' => fn ($c) => $c->project->title],
            'business' => ['label' => 'Business', 'render' => fn ($c) => $c->project->business->name],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($c) => self::badge($c->status)],
            'recovery' => ['label' => 'Recovery', 'render' => fn ($c) => $c->recovery_status->label()],
            'end_date' => ['label' => 'Ends', 'sortable' => true, 'render' => fn ($c) => $c->end_date?->format('d M Y') ?: '—'],
        ];
    }
}
