<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * ترتيب مقصود: الفرع أولًا، ثم الأدوار/الصلاحيات، ثم الحالات والإعدادات،
     * ثم مستخدم المدير (يعتمد على الفرع والدور).
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RolePermissionSeeder::class,
            StatusSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
