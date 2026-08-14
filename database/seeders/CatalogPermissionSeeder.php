<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات وحدة الكتالوج (Phase 2.2) بمخطط ADR-021 الدقيق.
 * قيم السمات (attribute_values) محكومة بصلاحيات attributes (مورد فرعي).
 */
class CatalogPermissionSeeder extends Seeder
{
    private array $resources = ['categories', 'brands', 'units', 'attributes', 'tags'];

    private array $actions = ['view', 'create', 'update', 'delete'];

    /**
     * التقييمات مورد للمراجعة لا للإنشاء: يكتبها الزبون في المتجر، وتُعتمد أو
     * تُرفض من اللوحة. فلا `create` لها.
     */
    private array $reviewActions = ['view', 'update', 'delete'];

    public function run(): void
    {
        $all = [];
        foreach ($this->resources as $resource) {
            foreach ($this->actions as $action) {
                $permission = "catalog.{$resource}.{$action}";
                Permission::findOrCreate($permission, 'web');
                $all[] = $permission;
            }
        }

        foreach ($this->reviewActions as $action) {
            $permission = "catalog.reviews.{$action}";
            Permission::findOrCreate($permission, 'web');
            $all[] = $permission;
        }

        // المدير والمدير التنفيذي: إدارة كاملة للكتالوج.
        foreach (['admin', 'manager'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($all);
            }
        }

        // المبيعات والمستودع: عرض فقط.
        $viewOnly = array_filter($all, fn ($p) => str_ends_with($p, '.view'));
        foreach (['sales', 'warehouse'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($viewOnly);
            }
        }
    }
}
