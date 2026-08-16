<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات «الميزانية اليومية».
 *
 * في migration لا في بذرة وحدها: النشر يشغّل `migrate` لا `seed`، وقد اختفى بند
 * «شحنات الاستيراد» من الإنتاج بهذا السبب بالضبط.
 *
 * التوزيع ضيّق عمدًا — الصفحة تكشف التكلفة والربح لكل صنف، فلا تُفتح لموظفات
 * المبيعات ولا للمسوّقين. و«الإدخال» منفصل عن «الاطّلاع» لأنه يكتب أرقامًا
 * يُبنى عليها قرارُ إيقاف الصرف.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.ad_budget.view',
        'reports.ad_budget.manage',
    ];

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'admin' => self::PERMISSIONS,
        'manager' => self::PERMISSIONS,
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
