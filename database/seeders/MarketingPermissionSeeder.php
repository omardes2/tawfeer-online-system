<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات أتمتة التسويق ولوحات مؤشّرات الأداء (Phase 6 / ADR-046, ADR-047).
 */
class MarketingPermissionSeeder extends Seeder
{
    private array $permissions = [
        'marketing.campaigns.view',
        'marketing.campaigns.manage',   // إنشاء/تعديل/تشغيل تجريبي/إيقاف
        'marketing.campaigns.approve',   // اعتماد وتفعيل الحملة
        'marketing.templates.manage',
        'marketing.reports.view',
        'kpis.view',                     // لوحات مؤشّرات الأداء الموسّعة
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales' => ['marketing.campaigns.view', 'marketing.reports.view', 'kpis.view'],
        'accountant' => ['marketing.reports.view', 'kpis.view'],
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
