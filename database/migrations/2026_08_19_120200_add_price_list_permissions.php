<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات قوائم الأسعار.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده.
 *
 * للأدمن والمدير فقط. القائمة تحدّد بكم يشتري التاجر، فهي **قرار تسعيرٍ لا
 * إدخال بيانات** — ومن يملك تعديلها يملك التصرّف في هامش الشركة.
 */
return new class extends Migration
{
    private const VIEW = 'catalog.price_lists.view';

    private const MANAGE = 'catalog.price_lists.manage';

    /** @var array<int, string> */
    private const ROLES = ['admin', 'manager'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach ([self::VIEW, self::MANAGE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role) {
            Role::where('name', $role)->first()?->givePermissionTo([self::VIEW, self::MANAGE]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::whereIn('name', [self::VIEW, self::MANAGE])->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
