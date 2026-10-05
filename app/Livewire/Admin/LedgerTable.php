<?php

namespace App\Livewire\Admin;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Livewire\Tables\DataTable;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Builder;

class LedgerTable extends DataTable
{
    protected function heading(): string
    {
        return 'Ledger transactions';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('ledger.view'), 403);
    }

    protected function defaultSort(): string
    {
        return 'posted_at';
    }

    protected function query(): Builder
    {
        return Transaction::query()->with(['user', 'project']);
    }

    protected function searchable(): array
    {
        return ['reference', 'description', 'user.name'];
    }

    protected function filters(): array
    {
        return [
            'type' => ['label' => 'Type', 'options' => TransactionType::options(), 'apply' => fn ($q, $v) => $q->where('type', $v)],
            'status' => ['label' => 'Status', 'options' => TransactionStatus::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)],
        ];
    }

    protected function columns(): array
    {
        return [
            'posted_at' => ['label' => 'Date', 'sortable' => true, 'render' => fn ($t) => $t->posted_at?->format('d M Y H:i')],
            'reference' => ['label' => 'Reference', 'sortable' => true, 'render' => fn ($t) => $t->reference],
            'type' => ['label' => 'Type', 'sortable' => true, 'render' => fn ($t) => $t->type->label()],
            'user' => ['label' => 'Party', 'render' => fn ($t) => $t->user?->name ?? 'Platform'],
            'project' => ['label' => 'Project', 'render' => fn ($t) => $t->project?->title ?? '—'],
            'amount' => ['label' => 'Amount', 'sortable' => true, 'render' => fn ($t) => self::money($t->amount, $t->currency)],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($t) => self::badge($t->status)],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.ledger-actions';
    }

    /** Corrections are reversals — history is never edited. Admin-only. */
    protected function perform(string $action, int $id, ?string $reason): void
    {
        $this->authorize('adjust', \App\Models\LedgerAccount::class);
        app(LedgerService::class)->reverse(Transaction::findOrFail($id), (string) $reason, auth()->user());
    }
}
