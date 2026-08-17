<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات جهات الاتصال التسويقية.
 *
 * في migration لا في بذرة وحدها: النشر يشغّل `migrate` لا `seed`.
 *
 * و«الإدارة» للمدير العام والمدير وحدهما: القائمة أرقامُ زبائن — بيانٌ شخصيّ
 * يُصدَّر ويُساء استعماله، ومن يملك تصديرها يملك أخذها معه.
 */
return new class extends Migration
{
    private const VIEW = 'marketing.contacts.view';

    private const MANAGE = 'marketing.contacts.manage';

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
