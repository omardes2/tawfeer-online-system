<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات محرّك حالة التوصيل القانوني (Phase 4.3 / ADR-038) بمخطط ADR-021.
 * الإغلاق المالي (close) مفصول ومقصور على المالية/الإدارة (يُفعّل التسوية والاستحقاق).
 */
class DeliveryStatusPermissionSeeder extends Seeder
{
    private array $permissions = [
        'shipping.delivery.view',          // عرض الحالة القانونية + السجلّ + الخطّ الزمني + التقارير
        'shipping.delivery.manage',        // انتقالات يدوية + إدارة الاستثناءات (SLA/تصعيد/ملاحظات)
        'shipping.delivery.sync',          // استيعاب حالة مزوّد (webhook/مزامنة)
        'shipping.delivery.close',         // الإغلاق المالي النهائي (يُفعّل التسوية) — مقصور
        'shipping.delivery.fees',          // إدارة مكوّنات رسوم الشحنة
    ];

    private array $grants = [
        'manager' => ['*'],
        'delivery_ops' => ['shipping.delivery.view', 'shipping.delivery.manage', 'shipping.delivery.sync', 'shipping.delivery.fees'],
        'finance' => ['shipping.delivery.view', 'shipping.delivery.close', 'shipping.delivery.fees'],
        'warehouse' => ['shipping.delivery.view', 'shipping.delivery.manage'],
        'sales' => ['shipping.delivery.view'],
        'accountant' => ['shipping.delivery.view'],
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
