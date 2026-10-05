<?php

namespace App\Livewire\Admin;

use App\Enums\DepositStatus as D;
use App\Livewire\Tables\DataTable;
use App\Models\Deposit;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\Builder;

class DepositsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Deposits';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('deposits.view'), 403);
    }

    protected function query(): Builder
    {
        return Deposit::query()->with('user');
    }

    protected function searchable(): array
    {
        return ['reference', 'payment_reference', 'user.name'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => D::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return 'No deposits yet.';
    }

    protected function columns(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'sortable' => true, 'render' => fn ($d) => $d->reference],
            'investor' => ['label' => 'Investor', 'render' => fn ($d) => $d->user->name],
            'payment_reference' => ['label' => 'Payment ref.', 'render' => fn ($d) => $d->payment_reference ?: '—'],
            'amount' => ['label' => 'Amount', 'sortable' => true, 'render' => fn ($d) => self::money($d->amount, $d->currency)],
            'created_at' => ['label' => 'Requested', 'sortable' => true, 'render' => fn ($d) => $d->created_at->format('d M Y H:i')],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($d) => self::badge($d->status)],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.deposit-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        abort_unless(auth()->user()->can('deposits.verify'), 403);
        $d = Deposit::findOrFail($id);
        $action === 'verify'
            ? app(WalletService::class)->verifyDeposit($d, auth()->user())
            : app(WalletService::class)->rejectDeposit($d, auth()->user(), (string) $reason);
    }
}
