<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;

class BusinessesTable extends DataTable
{
    protected function heading(): string
    {
        return 'Businesses';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('businesses.view'), 403);
    }

    protected function query(): Builder
    {
        return Business::query()->with('user')->withCount('projects');
    }

    protected function searchable(): array
    {
        return ['name', 'industry', 'user.email'];
    }

    protected function emptyTitle(): string
    {
        return 'No businesses have registered yet.';
    }

    protected function columns(): array
    {
        return [
            'name' => ['label' => 'Business', 'sortable' => true, 'render' => fn ($b) => $b->name],
            'industry' => ['label' => 'Industry', 'render' => fn ($b) => $b->industry ?: '—'],
            'kyc_status' => ['label' => 'KYC', 'sortable' => true, 'render' => fn ($b) => self::badge($b->kyc_status)],
            'projects' => ['label' => 'Projects', 'render' => fn ($b) => $b->projects_count],
            'created_at' => ['label' => 'Joined', 'sortable' => true, 'render' => fn ($b) => $b->created_at->format('d M Y')],
        ];
    }
}
