<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات نظام التقارير الجديد (المبيعات + الذمم + الميزانية اليومية):
 * - reports.sales_summary.view: تقارير المبيعات (حسب الزبون/المنتج/الموظف).
 * - reports.statements.view: كشوف الحسابات (ذمم العملاء/الموردين).
 * - reports.ad_budget.view/manage: الميزانية اليومية — للمدير ومدير النظام وحدهما،
 *   فهي تكشف التكلفة والربح لكل صنف.
 * غير ممنوحة لموظف المبيعات/المسوّق (تقارير إدارية مجمّعة).
 *
 * ولها نظيرٌ في migration (`add_ad_budget_permissions`) لأن النشر يشغّل `migrate`
 * لا `seed`. المساران يتفقان عمدًا.
 */
class BusinessReportsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'reports.sales_summary.view',
        'reports.statements.view',
        'reports.ad_budget.view',
        'reports.ad_budget.manage',
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
