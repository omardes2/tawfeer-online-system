<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات نظام التقارير الجديد (المبيعات + الذمم). صلاحيتان فقط:
 * - reports.sales_summary.view: تقارير المبيعات (حسب الزبون/المنتج/الموظف).
 * - reports.statements.view: كشوف الحسابات (ذمم العملاء/الموردين).
 * غير ممنوحة لموظف المبيعات/المسوّق (تقارير إدارية مجمّعة).
 */
class BusinessReportsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'reports.sales_summary.view',
        'reports.statements.view',
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales_supervisor' => ['reports.sales_summary.view'],
        'accountant' => ['reports.statements.view'],
        'finance' => ['reports.statements.view'],
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
