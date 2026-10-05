<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Audit log';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('audit.view'), 403);
    }

    protected function query(): Builder
    {
        return AuditLog::query()->with('user');
    }

    protected function searchable(): array
    {
        return ['action', 'reason', 'user.name'];
    }

    protected function emptyTitle(): string
    {
        return 'No audit entries yet.';
    }

    protected function columns(): array
    {
        return [
            'created_at' => ['label' => 'When', 'sortable' => true, 'render' => fn ($a) => $a->created_at->format('d M Y H:i:s')],
            'user' => ['label' => 'User', 'render' => fn ($a) => $a->user?->name ?? 'System'],
            'action' => ['label' => 'Action', 'sortable' => true, 'render' => fn ($a) => $a->action],
            'subject' => ['label' => 'Record', 'render' => fn ($a) => $a->auditable_type ? class_basename($a->auditable_type).' #'.$a->auditable_id : '—'],
            'change' => ['label' => 'Change', 'render' => fn ($a) => $a->new_values ? json_encode($a->new_values) : '—'],
            'reason' => ['label' => 'Reason', 'render' => fn ($a) => $a->reason ?: '—'],
        ];
    }
}
