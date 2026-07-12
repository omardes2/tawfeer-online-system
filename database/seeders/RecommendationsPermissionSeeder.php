<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات محرّك التوصيات (Phase 6 / ADR-045).
 */
class RecommendationsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'recommendations.view',    // عرض القواعد والاستثناءات
        'recommendations.manage',  // إدارة توصيات يدوية واستثناءات
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales' => ['recommendations.view'],
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
