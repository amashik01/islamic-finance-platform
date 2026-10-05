<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view', 'users.manage',
        'investors.view', 'investors.manage',
        'businesses.view', 'businesses.manage',
        'kyc.view', 'kyc.review', 'kyc.approve', 'kyc.reject',
        'projects.view', 'projects.create', 'projects.edit', 'projects.review', 'projects.approve', 'projects.reject',
        'contracts.view', 'contracts.manage',
        'investments.view', 'investments.manage',
        'wallet.view', 'ledger.view', 'ledger.adjust',
        'deposits.view', 'deposits.verify',
        'withdrawals.view', 'withdrawals.approve', 'withdrawals.reject',
        'settlements.view', 'settlements.manage',
        'shariah.review',
        'reports.view',
        'audit.view',
        'settings.manage',
        'roles.manage',
        'wakils.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate(UserRole::Admin->value, 'web')->syncPermissions(self::PERMISSIONS);

        Role::findOrCreate(UserRole::Manager->value, 'web')->syncPermissions(array_diff(self::PERMISSIONS, [
            'ledger.adjust', 'roles.manage', 'settings.manage', 'users.manage',
        ]));

        Role::findOrCreate(UserRole::Staff->value, 'web')->syncPermissions([
            'users.view', 'investors.view', 'businesses.view',
            'kyc.view', 'kyc.review',
            'projects.view', 'projects.review',
            'contracts.view', 'investments.view',
            'wallet.view', 'deposits.view', 'withdrawals.view', 'settlements.view',
        ]);

        Role::findOrCreate(UserRole::Investor->value, 'web')->syncPermissions([]);
        Role::findOrCreate(UserRole::Business->value, 'web')->syncPermissions(['projects.create', 'projects.edit']);
        Role::findOrCreate(UserRole::Wakil->value, 'web')->syncPermissions([]);   // appointed, not operating: no permissions

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
