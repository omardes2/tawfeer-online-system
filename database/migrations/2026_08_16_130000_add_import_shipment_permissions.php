<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات شحنات الاستيراد (الكونتينرات) — كانت في البذرة وحدها.
 *
 * أُضيفت مع الميزة إلى `PurchasingPermissionSeeder` فقط، والنشر يشغّل `migrate`
 * لا `seed`: فلم توجد الصلاحية على الإنتاج أصلًا، واختفى بند «شحنات الاستيراد»
 * من القائمة الجانبية حتى عن مدير النظام — فتعذّر إنشاء أي شحنة، وبقيت قائمة
 * «الشحنة/الكونتينر» في فاتورة المصاريف فارغة بلا سبب ظاهر للمستخدم.
 *
 * التوزيع هنا نسخةٌ من البذرة حرفيًّا كي لا يفترق المسـاران: الإغلاق صلاحية
 * منفصلة عن الإدارة لأنه يُنشئ قيدًا يُقفل فرق التقدير، والمستودع يرى ولا يُغلق.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchasing.shipments.view',
        'purchasing.shipments.manage',
        'purchasing.shipments.close',
    ];

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'admin' => self::PERMISSIONS,
        'manager' => self::PERMISSIONS,
        'accountant' => self::PERMISSIONS,
        'warehouse' => ['purchasing.shipments.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::GRANTS as $role => $abilities) {
            Role::where('name', $role)->first()?->givePermissionTo($abilities);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
