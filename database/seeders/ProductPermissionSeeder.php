<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المنتجات (Phase 2.3) + صلاحية رؤية التكلفة (ADR-013).
 */
class ProductPermissionSeeder extends Seeder
{
    private array $productPermissions = [
        'catalog.products.view', 'catalog.products.create', 'catalog.products.update', 'catalog.products.delete',
    ];

    public function run(): void
    {
        foreach ($this->productPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // رؤية حقول التكلفة/الربح (ADR-013).
        Permission::findOrCreate('pricing.view_cost', 'web');

        // المدير والمدير التنفيذي: إدارة كاملة للمنتجات.
        foreach (['admin', 'manager'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($this->productPermissions);
            }
        }

        // المبيعات والمستودع والمحاسب: عرض المنتجات فقط.
        foreach (['sales', 'warehouse', 'accountant'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo('catalog.products.view');
            }
        }

        // رؤية التكلفة: المدير، المدير التنفيذي، المحاسب.
        foreach (['admin', 'manager', 'accountant'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo('pricing.view_cost');
            }
        }
    }
}
