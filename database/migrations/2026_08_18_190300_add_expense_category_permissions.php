<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات تصنيفات المصروفات.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده، وصلاحيةٌ تعيش في البذرة
 * فقط لا توجد على الإنتاج أصلًا — فيختفي البند من القائمة عن مدير النظام نفسه
 * بلا سبب ظاهر.
 *
 * التعريف صلاحية إدارية لأنه **يفتح حسابًا في دليل المحاسبة**؛ والعرض يُمنح
 * لمن يُدخل المصروفات كي يختار من القائمة.
 */
return new class extends Migration
{
    private const VIEW = 'accounting.expense_categories.view';

    private const MANAGE = 'accounting.expense_categories.manage';

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'admin' => [self::VIEW, self::MANAGE],
        'manager' => [self::VIEW, self::MANAGE],
        'accountant' => [self::VIEW, self::MANAGE],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach ([self::VIEW, self::MANAGE] as $permission) {
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

        Permission::whereIn('name', [self::VIEW, self::MANAGE])->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
