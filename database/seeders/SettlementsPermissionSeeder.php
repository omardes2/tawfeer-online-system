<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات التسويات المالية (Phase 4.6 / ADR-041) بمخطط ADR-021.
 */
class SettlementsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'settlements.view',
        'settlements.manage',    // إنشاء/إضافة سطور
        'settlements.reconcile',  // مطابقة وكشف تباين
        'settlements.post',       // ترحيل محاسبي + إقفال (مالية/إدارة)
    ];

    private array $grants = [
        'manager' => ['*'],
        'finance' => ['settlements.view', 'settlements.manage', 'settlements.reconcile', 'settlements.post'],
        'accountant' => ['settlements.view', 'settlements.reconcile'],
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
