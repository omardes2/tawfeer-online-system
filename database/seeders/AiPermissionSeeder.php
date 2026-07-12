<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044).
 */
class AiPermissionSeeder extends Seeder
{
    private array $permissions = [
        'ai.content.use',    // استخدام مساعد التوليد داخل صفحة المنتج
        'ai.config.manage',  // إدارة إعدادات المزوّد/الحدود
        'ai.logs.view',      // عرض سجلّ الاستخدام والتكلفة
    ];

    private array $grants = [
        'manager' => ['*'],
        'sales' => ['ai.content.use'],
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
