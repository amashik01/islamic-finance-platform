<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\Investor;
use Illuminate\Database\Eloquent\Builder;

class InvestorsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Investors';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('investors.view'), 403);
    }

    protected function query(): Builder
    {
        return Investor::query()->with('user')->withSum('investments as invested_total', 'amount');
    }

    protected function searchable(): array
    {
        return ['user.name', 'user.email'];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.impersonate-actions';
    }

    protected function emptyTitle(): string
    {
        return 'No investors have registered yet.';
    }

    protected function columns(): array
    {
        return [
            'name' => ['label' => 'Investor', 'render' => fn ($i) => $i->user->name],
            'email' => ['label' => 'Email', 'render' => fn ($i) => $i->user->email],
            'kyc_status' => ['label' => 'KYC', 'sortable' => true, 'render' => fn ($i) => self::badge($i->kyc_status)],
            'invested' => ['label' => 'Invested', 'render' => fn ($i) => self::money((int) $i->invested_total)],
            'created_at' => ['label' => 'Joined', 'sortable' => true, 'render' => fn ($i) => $i->created_at->format('d M Y')],
        ];
    }
}
