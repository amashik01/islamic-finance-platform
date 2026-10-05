<?php

namespace App\Livewire\Admin;

use App\Livewire\Tables\DataTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UsersTable extends DataTable
{
    protected function heading(): string
    {
        return 'Staff and users';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('users.view'), 403);
    }

    protected function query(): Builder
    {
        return User::query()->with('roles');
    }

    protected function searchable(): array
    {
        return ['name', 'email'];
    }

    protected function emptyTitle(): string
    {
        return 'No users.';
    }

    protected function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'sortable' => true, 'render' => fn ($u) => $u->name],
            'email' => ['label' => 'Email', 'sortable' => true, 'render' => fn ($u) => $u->email],
            'roles' => ['label' => 'Role', 'render' => fn ($u) => $u->roles->pluck('name')->implode(', ')],
            'status' => ['label' => 'Status', 'render' => fn ($u) => self::badge($u->status)],
            'last_login_at' => ['label' => 'Last login', 'sortable' => true, 'render' => fn ($u) => $u->last_login_at?->diffForHumans() ?: 'Never'],
        ];
    }
}
