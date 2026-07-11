<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المشتريات (Phase 2.5) بمخطط ADR-021.
 */
class PurchasingPermissionSeeder extends Seeder
{
    private array $permissions = [
        'purchasing.suppliers.view', 'purchasing.suppliers.create', 'purchasing.suppliers.update', 'purchasing.suppliers.delete',
        'purchasing.orders.view', 'purchasing.orders.create', 'purchasing.orders.update', 'purchasing.orders.approve', 'purchasing.orders.cancel', 'purchasing.orders.close', 'purchasing.orders.delete',
        'purchasing.receipts.view', 'purchasing.receipts.create', 'purchasing.receipts.post',
        'purchasing.returns.view', 'purchasing.returns.create', 'purchasing.returns.approve', 'purchasing.returns.post',
    ];

    private array $grants = [
        'manager' => ['*'],
        'warehouse' => [
            'purchasing.suppliers.view',
            'purchasing.orders.view', 'purchasing.orders.create',
            'purchasing.receipts.view', 'purchasing.receipts.create', 'purchasing.receipts.post',
            'purchasing.returns.view', 'purchasing.returns.create',
        ],
        'accountant' => [
            'purchasing.suppliers.view', 'purchasing.orders.view', 'purchasing.receipts.view', 'purchasing.returns.view',
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
