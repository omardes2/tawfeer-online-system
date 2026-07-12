<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المخزون (Phase 2.4) بمخطط ADR-021.
 */
class InventoryPermissionSeeder extends Seeder
{
    private array $permissions = [
        'inventory.stocks.view',
        'inventory.movements.view',
        'inventory.operations.receive',
        'inventory.operations.issue',
        'inventory.operations.transfer',
        'inventory.reservations.view',
        'inventory.reservations.create',
        'inventory.reservations.release',
        'inventory.adjustments.view',
        'inventory.adjustments.create',
        'inventory.adjustments.update',
        'inventory.adjustments.approve',
        'inventory.adjustments.post',
        'inventory.adjustments.delete',
        // Phase 5 — جرد/تنبيهات/دفعات
        'inventory.counts.view',
        'inventory.counts.manage',
        'inventory.alerts.view',
        'inventory.batch',
    ];

    private array $grants = [
        'manager' => ['*'],
        'warehouse' => [
            'inventory.stocks.view', 'inventory.movements.view',
            'inventory.operations.receive', 'inventory.operations.issue', 'inventory.operations.transfer',
            'inventory.reservations.view', 'inventory.reservations.create', 'inventory.reservations.release',
            'inventory.adjustments.view', 'inventory.adjustments.create', 'inventory.adjustments.update',
            'inventory.counts.view', 'inventory.counts.manage', 'inventory.alerts.view', 'inventory.batch',
        ],
        'accountant' => ['inventory.stocks.view', 'inventory.movements.view', 'inventory.alerts.view'],
        'sales' => ['inventory.stocks.view', 'inventory.reservations.view'],
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
