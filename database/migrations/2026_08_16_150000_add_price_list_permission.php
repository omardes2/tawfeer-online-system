<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحية «الأصناف والأسعار» — قائمة أسعار للمسوّق.
 *
 * صلاحية مستقلّة لا `catalog.*`: المسوّق يحتاج **قراءة** الأسعار ليعرف ما يبيع،
 * ولا يجوز أن يفتح له ذلك بابَ الكتالوج (إنشاء صنف أو تعديل سعر أو رؤية
 * التكلفة). الترحيل لا البذرة وحدها لأن النشر يشغّل `migrate` لا `seed`.
 */
return new class extends Migration
{
    private const PERMISSION = 'catalog.price_list.view';

    /** المدير والمدير التنفيذي والمسوّق — لا أحد غيرهم. */
    private const ROLES = ['admin', 'manager', 'affiliate'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        Permission::findOrCreate(self::PERMISSION, 'web');

        foreach (self::ROLES as $name) {
            Role::where('name', $name)->first()?->givePermissionTo(self::PERMISSION);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
