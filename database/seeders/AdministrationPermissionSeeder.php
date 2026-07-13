<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات الإدارة (المستخدمون/الأدوار/الإعدادات/لوحة التحكّم) — Production.
 * مخطط ADR-021: `{module}.{resource}.{action}`.
 */
class AdministrationPermissionSeeder extends Seeder
{
    private array $permissions = [
        'settings.users.view', 'settings.users.create', 'settings.users.update', 'settings.users.delete',
        'settings.roles.view', 'settings.roles.manage',
        'settings.system.view', 'settings.system.manage',
        'dashboard.view',
    ];

    private array $grants = [
        'manager' => ['settings.users.view', 'settings.system.view', 'dashboard.view'],
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
                $role->givePermissionTo($abilities);
            }
        }
    }
}
