<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات «طلبات لم تكتمل».
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده، فصلاحيةٌ تعيش في البذرة
 * لا توجد على الإنتاج أصلًا ويختفي البند من القائمة بلا سبب ظاهر.
 *
 * **لا تُمنح للمسوّق.** الشاشة قائمة أرقام زبائن المتجر كلّه لا زبائن المسوّق،
 * ومنحُها له تسليمُ قاعدة عملاء الشركة لمن قد يعمل غدًا عند غيرها.
 */
return new class extends Migration
{
    private const VIEW = 'sales.abandoned_checkouts.view';

    private const MANAGE = 'sales.abandoned_checkouts.manage';

    /** @var array<int, string> */
    private const ROLES = ['admin', 'manager', 'sales_supervisor', 'sales'];

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
