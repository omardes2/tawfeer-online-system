<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المرتجعات/الاستبدال — RMA (Phase 4.4 / ADR-040) بمخطط ADR-021.
 * تعكس مسار الموافقة: مبيعات → مشرف → مستودع → قرار نهائي.
 */
class ReturnsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'returns.view',
        'returns.create',    // مبيعات
        'returns.approve',    // مشرف مبيعات
        'returns.receive',    // مستودع
        'returns.inspect',    // مستودع
        'returns.finalize',   // قرار نهائي (مالية/إدارة)
        'returns.refund',     // تنفيذ الاسترداد
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales' => ['returns.view', 'returns.create'],
        'sales_supervisor' => ['returns.view', 'returns.create', 'returns.approve'],
        'warehouse' => ['returns.view', 'returns.receive', 'returns.inspect'],
        'finance' => ['returns.view', 'returns.finalize', 'returns.refund'],
        'accountant' => ['returns.view'],
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
