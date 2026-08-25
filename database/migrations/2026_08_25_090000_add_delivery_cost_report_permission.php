<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحية تقرير تكلفة التوصيل.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده.
 *
 * وصلاحيةٌ مستقلّة لا `reports.sales_summary.view`: هذا التقرير يكشف ما تدفعه
 * لشركة التوصيل — رقمُ تكلفةٍ تفاوضيّ لا يُفتح لمن يقرأ تقارير المبيعات.
 * ويُحصر بمدير النظام في مرحلة التجربة كبقيّة ما بُني حديثًا.
 */
return new class extends Migration
{
    private const PERMISSION = 'reports.delivery_cost.view';

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
