<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;

class WalletsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Wallets';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('wallet.view'), 403);
    }

    protected function query(): Builder
    {
        return Wallet::query()->with(['user', 'accounts']);
    }

    protected function searchable(): array
    {
        return ['user.name', 'user.email'];
    }

    protected function emptyTitle(): string
    {
        return 'No wallets yet.';
    }

    protected function columns(): array
    {
        return [
            'user' => ['label' => 'Investor', 'render' => fn ($w) => $w->user->name],
            'available' => ['label' => 'Available', 'render' => fn ($w) => self::money((int) $w->accounts->firstWhere('type', \App\Enums\LedgerAccountType::InvestorAvailable)?->balance, $w->currency)],
            'invested' => ['label' => 'Invested', 'render' => fn ($w) => self::money((int) $w->accounts->firstWhere('type', \App\Enums\LedgerAccountType::InvestorInvested)?->balance, $w->currency)],
            'pending' => ['label' => 'Pending', 'render' => fn ($w) => self::money((int) $w->accounts->firstWhere('type', \App\Enums\LedgerAccountType::InvestorPending)?->balance, $w->currency)],
            'status' => ['label' => 'Status', 'render' => fn ($w) => $w->status],
        ];
    }
}
