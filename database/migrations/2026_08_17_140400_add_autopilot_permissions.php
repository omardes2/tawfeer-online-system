<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات الطيّار الآلي.
 *
 * في migration لا في بذرة وحدها: النشر يشغّل `migrate` لا `seed`.
 *
 * و«الإدارة» لا تُمنح إلّا للمدير العام: هي الصلاحية الوحيدة في النظام التي
 * **تُنفق مالًا خارجه**. حتى `manager` يرى ولا يضبط — لأن ضبط السقف أو تحويل
 * الوضع إلى «فرملة» قرارُ صاحب العمل لا قرارُ مشرفٍ على وردية.
 */
return new class extends Migration
{
    private const VIEW = 'marketing.autopilot.view';

    private const MANAGE = 'marketing.autopilot.manage';

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'admin' => [self::VIEW, self::MANAGE],
        'manager' => [self::VIEW],
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
