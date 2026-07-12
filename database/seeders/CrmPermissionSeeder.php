<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات CRM/العملاء (Phase 2.10) بمخطط ADR-021.
 */
class CrmPermissionSeeder extends Seeder
{
    private array $permissions = [
        'crm.customers.view', 'crm.customers.create', 'crm.customers.update', 'crm.customers.delete',
        'crm.customers.block', 'crm.customers.merge',
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales' => ['crm.customers.view', 'crm.customers.create', 'crm.customers.update'],
        'affiliate' => ['crm.customers.view'],
        'accountant' => ['crm.customers.view'],
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
