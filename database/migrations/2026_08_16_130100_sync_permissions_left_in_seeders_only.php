<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات أُضيفت مع ميزاتها إلى **البذور وحدها** فلم تصل الإنتاج (النشر يشغّل
 * `migrate` لا `seed`) — نفس علّة صلاحيات شحنات الاستيراد.
 *
 * أثرها صامت ومُربك: الميزة منشورة وشاشتها موجودة، لكن بندها يختفي من القائمة
 * أو يردّ 403، فيبدو الأمر عطلًا في الميزة لا نقصًا في صلاحية.
 *
 *  • `catalog.reviews.*` — مراجعة تقييمات الزبائن (عرض/تعديل/حذف).
 *  • `inventory.alerts.view` لموظف المبيعات — تنبيهات النقص؛ الأرصدة مرئية له
 *    أصلًا فلا يكشف بيانات جديدة.
 *
 * التوزيع منقول حرفيًّا عن البذرتين: لا يوسَّع وصولُ أحد ولا يُضيَّق.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const REVIEW_PERMISSIONS = [
        'catalog.reviews.view',
        'catalog.reviews.update',
        'catalog.reviews.delete',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::REVIEW_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // إدارة كاملة للكتالوج، وعرضٌ فقط لمن يقرأ الكتالوج.
        foreach (['admin', 'manager'] as $name) {
            Role::where('name', $name)->first()?->givePermissionTo(self::REVIEW_PERMISSIONS);
        }
        foreach (['sales', 'warehouse'] as $name) {
            Role::where('name', $name)->first()?->givePermissionTo('catalog.reviews.view');
        }

        Permission::findOrCreate('inventory.alerts.view', 'web');
        Role::where('name', 'sales')->first()?->givePermissionTo('inventory.alerts.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // `inventory.alerts.view` تبقى: أدوارٌ أخرى تعتمدها من قبل هذا الترحيل،
        // وحذفها يُعطّل شاشة تنبيهات النقص لمن كان يراها أصلًا.
        Permission::whereIn('name', self::REVIEW_PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
