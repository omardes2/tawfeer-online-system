<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات فواتير الموردين/الشراء (REQUIREMENTS §2.5). مخطط ADR-021.
 */
class PurchaseInvoicePermissionSeeder extends Seeder
{
    private array $permissions = [
        'purchasing.invoices.view',
        'purchasing.invoices.create',
        'purchasing.invoices.approve',
        'purchasing.invoices.post',
        'purchasing.invoices.pay',
        'purchasing.invoices.delete',
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
