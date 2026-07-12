<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * الأدوار والصلاحيات (المبدأ 12: RBAC فقط).
 *
 * الصلاحيات مجمّعة حسب الوحدة. المدير يحصل عليها كلها، وبقية الأدوار
 * تحصل على مجموعات مناسبة. كلها قابلة للإدارة لاحقًا من لوحة التحكم.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * صلاحيات أساسية لكل وحدة (view/create/update/delete حيثما يناسب).
     */
    private array $permissions = [
        // النظام
        'dashboard.view',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.manage',
        'branches.manage',
        'settings.manage',
        'statuses.manage',
        'audit.view',
        // الوحدات (مبدئية — تتوسّع مع كل مرحلة)
        'catalog.view', 'catalog.manage',
        'inventory.view', 'inventory.manage',
        'orders.view', 'orders.manage',
        'purchasing.view', 'purchasing.manage',
        'accounting.view', 'accounting.manage',
        'crm.view', 'crm.manage',
        'affiliate.view', 'affiliate.manage',
        'messaging.view', 'messaging.manage',
        'reports.view',
    ];

    /**
     * الأدوار وصلاحياتها. '*' تعني كل الصلاحيات.
     */
    private array $roles = [
        'admin' => ['*'],
        'manager' => [
            'dashboard.view', 'users.view', 'catalog.view', 'catalog.manage',
            'inventory.view', 'inventory.manage', 'orders.view', 'orders.manage',
            'purchasing.view', 'crm.view', 'reports.view', 'audit.view',
        ],
        'sales' => [
            'dashboard.view', 'orders.view', 'orders.manage', 'catalog.view',
            'crm.view', 'crm.manage', 'messaging.view', 'messaging.manage',
        ],
        // مشرف مبيعات (Phase 4.1): يعتمد تعديلات السعر/الخصم فوق الحدود.
        'sales_supervisor' => [
            'dashboard.view', 'orders.view', 'orders.manage', 'catalog.view',
            'crm.view', 'crm.manage', 'messaging.view', 'messaging.manage', 'reports.view',
        ],
        'accountant' => [
            'dashboard.view', 'accounting.view', 'accounting.manage',
            'orders.view', 'purchasing.view', 'reports.view',
        ],
        'warehouse' => [
            'dashboard.view', 'inventory.view', 'inventory.manage',
            'purchasing.view', 'orders.view',
        ],
        'affiliate' => [
            'dashboard.view', 'affiliate.view',
        ],
        // موظف مالية (Phase 4.2): اعتماد/صرف العمولات والتسويات لاحقًا.
        'finance' => [
            'dashboard.view', 'accounting.view', 'accounting.manage',
            'orders.view', 'reports.view',
        ],
        'customer' => [],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ($this->roles as $roleName => $abilities) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($abilities === ['*']) {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($abilities);
            }
        }
    }
}
