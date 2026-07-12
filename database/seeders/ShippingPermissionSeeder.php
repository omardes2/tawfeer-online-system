<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات الشحن والجغرافيا (Phase 2.7) بمخطط ADR-021.
 */
class ShippingPermissionSeeder extends Seeder
{
    private array $permissions = [
        'shipping.shipments.view', 'shipping.shipments.create', 'shipping.shipments.update',
        'shipping.shipments.dispatch', 'shipping.shipments.deliver', 'shipping.shipments.fail',
        'shipping.override_cost',
        'settings.geography.view', 'settings.geography.manage',
    ];

    private array $grants = [
        'manager' => ['*'],
        'warehouse' => [
            'shipping.shipments.view', 'shipping.shipments.create', 'shipping.shipments.dispatch',
            'settings.geography.view',
        ],
        'sales' => [
            'shipping.shipments.view', 'shipping.shipments.deliver', 'shipping.shipments.fail',
            'settings.geography.view',
        ],
        'accountant' => [
            'shipping.shipments.view', 'settings.geography.view',
        ],
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
