<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المحاسبة (Phase 2.9) بمخطط ADR-021.
 */
class AccountingPermissionSeeder extends Seeder
{
    private array $permissions = [
        'accounting.accounts.view', 'accounting.accounts.manage',
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.post', 'accounting.journal.reverse',
        'accounting.reports.view',
    ];

    private array $grants = [
        'manager' => ['*'],
        'accountant' => ['*'],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($admin = Role::where('name', 'admin')->first()) {
            $admin->givePermissionTo($this->permissions);
        }

        foreach ($this->grants as $roleName => $abilities) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($abilities === ['*'] ? $this->permissions : $abilities);
            }
        }
    }
}
