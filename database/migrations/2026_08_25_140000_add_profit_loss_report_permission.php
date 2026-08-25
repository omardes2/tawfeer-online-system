<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحية قائمة الأرباح والخسائر.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده.
 *
 * وصلاحيةٌ مستقلّة عن تقارير المبيعات: هذه القائمة تكشف تكلفة الشراء والهامش
 * والصرف الإعلاني وأجرة التوصيل معًا — أي بنية ربح الشركة كاملة. تُحصر بمدير
 * النظام في مرحلة التجربة كبقيّة ما بُني حديثًا.
 */
return new class extends Migration
{
    private const PERMISSION = 'reports.profit_loss.view';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::where('name', 'admin')->first()?->givePermissionTo(self::PERMISSION);

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
