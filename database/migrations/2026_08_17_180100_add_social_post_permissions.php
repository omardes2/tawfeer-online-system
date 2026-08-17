<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات منشورات التواصل الاجتماعي.
 *
 * في migration لا في بذرة وحدها: النشر يشغّل `migrate` لا `seed`.
 *
 * وتُمنح لمن يكتب المحتوى فعلًا — المدير والتسويق — لا للمدير العام وحده:
 * هذه لا تُنفق مالًا ولا تكشف تكلفة، وحصرُها في شخصٍ واحد يجعل الصفحة تُهجَر.
 */
return new class extends Migration
{
    private const VIEW = 'marketing.social.view';

    private const MANAGE = 'marketing.social.manage';

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'admin' => [self::VIEW, self::MANAGE],
        'manager' => [self::VIEW, self::MANAGE],
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
