<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * ترتيب مقصود: الفرع أولًا، ثم الأدوار/الصلاحيات، ثم الحالات والإعدادات،
     * ثم مستخدم المدير (يعتمد على الفرع والدور)، ثم أسس Phase 2.1.
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RolePermissionSeeder::class,
            StatusSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
            // Phase 2.1 — البنية التنظيمية
            StructurePermissionSeeder::class,
            WarehouseSeeder::class,
            // Phase 2.2 — الكتالوج
            CatalogPermissionSeeder::class,
            UnitSeeder::class,
            // Phase 2.3 — المنتجات
            ProductPermissionSeeder::class,
        ]);
    }
}
