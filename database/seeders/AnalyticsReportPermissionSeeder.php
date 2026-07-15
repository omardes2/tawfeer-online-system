<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات وحدة «التقارير والتحليلات» — صلاحية لكل فئة تقارير.
 *
 * تُبقي صلاحية reports.view القديمة كما هي (تقارير Phase 4.7)، وتضيف صلاحيات
 * دقيقة لكل فئة في الوحدة الموسّعة. الأدمِن يملك الكل؛ المدير يملك الكل؛
 * المحاسب يرى المالية؛ المبيعات ترى المبيعات/العملاء/المنتجات.
 */
class AnalyticsReportPermissionSeeder extends Seeder
{
    /** @var array<string> */
    private array $permissions = [
        'reports.view',            // توافق: النظرة العامة والتقارير الأصلية
        'reports.executive.view',
        'reports.sales.view',
        'reports.customers.view',
        'reports.products.view',
        'reports.inventory.view',
        'reports.shipping.view',
        'reports.financial.view',
        'reports.employees.view',
        'reports.marketers.view',
        'reports.marketing.view',
        'reports.support.view',
        'reports.audit.view',
    ];

    /** @var array<string, array<string>> */
    private array $grants = [
        'manager' => ['*'],
        'accountant' => ['reports.view', 'reports.executive.view', 'reports.financial.view', 'reports.sales.view'],
        'sales' => ['reports.view', 'reports.sales.view', 'reports.customers.view', 'reports.products.view'],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (['admin', 'super-admin'] as $adminRole) {
            if ($role = Role::where('name', $adminRole)->first()) {
                $role->givePermissionTo($this->permissions);
            }
        }

        foreach ($this->grants as $roleName => $abilities) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($abilities === ['*'] ? $this->permissions : $abilities);
            }
        }
    }
}
