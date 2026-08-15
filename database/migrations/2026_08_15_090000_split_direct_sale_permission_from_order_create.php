<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * فصل «مبيعات مباشرة» عن إنشاء الطلب العادي.
 *
 * كانت السياسة تشتقّ الصلاحية: `sales.orders.create && sales.orders.view`.
 * فأيّ دور ينال العرض الكامل لسبب آخر تُفتح له نقطة بيع كاملة (تحصيل نقدي
 * وخصم مخزون فوري) بلا قصد — وهذا ما يجب ألّا يملكه المسوّق.
 *
 * الصلاحية الجديدة تُمنح صراحةً لمن يُقصد منحه فقط: مدير النظام والمدير
 * التنفيذي — وهما وحدهما من كانا يملكانها فعليًّا قبل هذا التغيير، فلا يتبدّل
 * وصول أحد. الترحيل هنا لا في البذرة وحدها لأن النشر يشغّل `migrate` لا `seed`.
 */
return new class extends Migration
{
    private const PERMISSION = 'sales.orders.create_direct';

    /** من كان يملكها فعليًّا بالاشتقاق القديم — لا توسيع ولا تضييق لأحد آخر. */
    private const ROLES = ['admin', 'manager'];

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
