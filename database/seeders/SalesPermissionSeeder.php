<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المبيعات (Phase 2.6) بمخطط ADR-021.
 */
class SalesPermissionSeeder extends Seeder
{
    private array $permissions = [
        'sales.orders.view', 'sales.orders.view_own', 'sales.orders.create', 'sales.orders.update', 'sales.orders.delete',
        'sales.orders.confirm', 'sales.orders.reserve', 'sales.orders.ship', 'sales.orders.deliver', 'sales.orders.cancel',
    ];

    private array $grants = [
        'manager' => ['*'],
        // موظف المبيعات: ينشئ الطلبات ويرى/يدير طلباته هو فقط (view_own بدل view الكاملة).
        'sales' => [
            'sales.orders.view_own', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.confirm', 'sales.orders.reserve', 'sales.orders.cancel',
        ],
        // المسوّق: ينشئ الطلبات ويرى طلباته هو فقط.
        'affiliate' => [
            'sales.orders.view_own', 'sales.orders.create',
        ],
        'warehouse' => [
            'sales.orders.view', 'sales.orders.ship', 'sales.orders.deliver',
        ],
        'accountant' => [
            'sales.orders.view',
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

        // حصر أصحاب «العرض الخاص»: سحب العرض الكامل إن كان ممنوحًا سابقًا لهذه الأدوار
        // (givePermissionTo يضيف فقط، فنسحب صراحةً حتى يُطبَّق التحديث على قاعدة بيانات قائمة).
        foreach (['sales', 'affiliate'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role && $role->hasPermissionTo('sales.orders.view')) {
                $role->revokePermissionTo('sales.orders.view');
            }
        }
    }
}
