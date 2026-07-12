<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات عمليات المبيعات (Phase 4.1 / ADR-037) بمخطط ADR-021.
 * assist = إنشاء طلب مُساعد؛ override_price = تجاوز الحد/التكلفة واعتماد التعديلات.
 */
class SalesOperationsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'sales.orders.assist',
        'sales.orders.override_price',
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales_supervisor' => ['*'],
        'sales' => ['sales.orders.assist'],
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
