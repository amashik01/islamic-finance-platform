<?php

namespace App\Livewire\Admin;

use App\Enums\WithdrawalStatus as W;
use App\Livewire\Tables\DataTable;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Eloquent\Builder;

class WithdrawalsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Withdrawals';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('withdrawals.view'), 403);
    }

    protected function query(): Builder
    {
        return Withdrawal::query()->with('user.investor');
    }

    protected function searchable(): array
    {
        return ['reference', 'user.name', 'user.email'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => W::options(), 'apply' => fn ($q, $v) => $q->where('status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return 'No withdrawal requests yet.';
    }

    protected function columns(): array
    {
        return [
            'reference' => ['label' => 'Withdrawal ID', 'sortable' => true, 'render' => fn ($w) => $w->reference],
            'investor' => ['label' => 'Investor', 'render' => fn ($w) => $w->user->name],
            'amount' => ['label' => 'Amount', 'sortable' => true, 'render' => fn ($w) => self::money($w->amount, $w->currency)],
            'created_at' => ['label' => 'Requested', 'sortable' => true, 'render' => fn ($w) => $w->created_at->format('d M Y H:i')],
            'kyc' => ['label' => 'KYC', 'render' => fn ($w) => $w->user->investor?->kyc_status->label()],
            'risk' => ['label' => 'Risk flag', 'render' => fn ($w) => $w->user->investor?->risk_flag ? 'Flagged' : 'None'],
            'status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($w) => self::badge($w->status)],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.withdrawal-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        $w = Withdrawal::findOrFail($id);
        $to = match ($action) {
            'review' => W::UnderReview, 'approve' => W::Approved, 'process' => W::Processing, 'paid' => W::Paid, 'reject' => W::Rejected,
        };
        $this->authorize($to === W::Rejected ? 'reject' : 'approve', $w);
        app(WalletService::class)->advanceWithdrawal($w, $to, auth()->user(), $reason);
    }
}
