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

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.user-actions';
    }

    /** Role changes are sensitive: admin-only, never on yourself, never to/from investor or business, always audited. */
    protected function perform(string $action, int $id, ?string $reason): void
    {
        auth()->user()->can('roles.manage') || throw new \Illuminate\Auth\Access\AuthorizationException();
        $target = User::findOrFail($id);
        $new = str_replace('role:', '', $action);
        $staffRoles = ['ADMIN', 'MANAGER', 'STAFF'];
        if ($target->id === auth()->id()) {
            throw new \App\Exceptions\FinancialException('You cannot change your own role.');
        }
        if (! in_array($new, $staffRoles, true) || ! $target->hasAnyRole($staffRoles)) {
            throw new \App\Exceptions\FinancialException('Only staff roles can be changed here.');
        }
        $old = $target->roles->pluck('name')->all();
        $target->syncRoles([$new]);
        app(\App\Services\Audit\AuditLogger::class)->record('role.changed', $target, ['roles' => $old], ['roles' => [$new]], $reason);
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
