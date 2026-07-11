<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات Phase 2.1 (الفروع/المستودعات/المواقع) بمخطط ADR-021 الدقيق
 * `{module}.{resource}.{action}`. تُزرع مع الوحدة (لا دفعة واحدة عملاقة).
 */
class StructurePermissionSeeder extends Seeder
{
    private array $permissions = [
        'settings.branches.view', 'settings.branches.create', 'settings.branches.update', 'settings.branches.delete',
        'settings.warehouses.view', 'settings.warehouses.create', 'settings.warehouses.update', 'settings.warehouses.delete',
        'settings.warehouse_locations.view', 'settings.warehouse_locations.create', 'settings.warehouse_locations.update', 'settings.warehouse_locations.delete',
    ];

    private array $grants = [
        'manager' => [
            'settings.branches.view',
            'settings.warehouses.view', 'settings.warehouses.create', 'settings.warehouses.update', 'settings.warehouses.delete',
            'settings.warehouse_locations.view', 'settings.warehouse_locations.create', 'settings.warehouse_locations.update', 'settings.warehouse_locations.delete',
        ],
        'warehouse' => [
            'settings.branches.view',
            'settings.warehouses.view',
            'settings.warehouse_locations.view', 'settings.warehouse_locations.create', 'settings.warehouse_locations.update',
        ],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // المدير يحصل على كل الصلاحيات الجديدة (المبدأ 12).
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
