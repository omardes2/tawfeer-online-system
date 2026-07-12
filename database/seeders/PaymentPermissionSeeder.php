<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المدفوعات (Phase 2.8) بمخطط ADR-021.
 */
class PaymentPermissionSeeder extends Seeder
{
    private array $permissions = [
        'payments.view', 'payments.create', 'payments.capture', 'payments.refund',
        'payments.methods.manage',
    ];

    private array $grants = [
        'manager' => ['*'],
        'accountant' => ['payments.view', 'payments.create', 'payments.capture', 'payments.refund'],
        'sales' => ['payments.view', 'payments.create'],
        'warehouse' => ['payments.view', 'payments.capture'], // تحصيل COD عند التسليم
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
