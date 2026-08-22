<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * حصر الميزات الجديدة بمدير النظام — **مرحلة تجربة**.
 *
 * الميزات المبنيّة حديثًا لم تُجرَّب على بيانات حقيقية بعد، وفتحُها للفريق قبل
 * التجربة يُنتج قراراتٍ مبنيّة على شاشةٍ لم تُراجَع: موظفٌ يتّصل بقائمةٍ خاطئة،
 * أو تاجرٌ يشتري بسعرٍ أُدخل تجريبًا. فتُحصَر بمدير النظام حتى تُعتمد.
 *
 * **هذه الهجرة مؤقّتة بطبيعتها.** لفتح ميزةٍ للفريق بعد اعتمادها تُمنح صلاحيتها
 * للدور من شاشة الأدوار، أو يُشغَّل `down` لإعادة التوزيع الأصلي كاملًا.
 *
 * ويُنشأ للتقريرين الجديدين صلاحيتان مستقلّتان: كانا على
 * `reports.sales_summary.view` المشتركة مع تقاريرَ قديمة يستعملها الفريق، فلم
 * يكن ممكنًا إغلاقهما دون إغلاق ما لا علاقة له بهما.
 */
return new class extends Migration
{
    /** صلاحيتان جديدتان تفصلان التقريرين الجديدين عن تقارير المبيعات القديمة. */
    private const NEW_PERMISSIONS = [
        'reports.product_decision.view',
        'reports.sales_by_location.view',
    ];

    /** كل ما بُني حديثًا — يُحصر بمدير النظام في مرحلة التجربة. */
    private const TRIAL_PERMISSIONS = [
        // طلبات لم تكتمل
        'sales.abandoned_checkouts.view',
        'sales.abandoned_checkouts.manage',
        // قوائم أسعار التجّار
        'catalog.price_lists.view',
        'catalog.price_lists.manage',
        // الميزانية اليومية (بعروضها المشتركة وعتباتها الجديدة)
        'reports.ad_budget.view',
        'reports.ad_budget.manage',
        // التقريران الجديدان
        'reports.product_decision.view',
        'reports.sales_by_location.view',
        // الصندوق الموحّد ووكيل المبيعات
        'inbox.view',
        'inbox.reply',
        'inbox.assign',
        'ai_agent.handoff',
        'ai_agent.toggle',
        'ai_agent.runs.view',
        'ai_agent.knowledge.view',
        'ai_agent.knowledge.manage',
    ];

    /** التوزيع الأصلي — يُعاد عند `down` وحده. */
    private const ORIGINAL_GRANTS = [
        'manager' => [
            'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
            'catalog.price_lists.view', 'catalog.price_lists.manage',
            'reports.ad_budget.view', 'reports.ad_budget.manage',
            'reports.product_decision.view', 'reports.sales_by_location.view',
            'inbox.view', 'inbox.reply', 'inbox.assign',
            'ai_agent.handoff', 'ai_agent.toggle', 'ai_agent.runs.view',
            'ai_agent.knowledge.view', 'ai_agent.knowledge.manage',
        ],
        'sales_supervisor' => [
            'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
            'inbox.view', 'inbox.reply', 'inbox.assign',
            'ai_agent.handoff', 'ai_agent.knowledge.view',
        ],
        'sales' => [
            'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
            'inbox.view', 'inbox.reply', 'ai_agent.handoff',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::NEW_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::where('name', 'admin')->first()?->givePermissionTo(self::NEW_PERMISSIONS);

        // السحب من كل دورٍ عدا مدير النظام — لا من الأدوار المعروفة وحدها:
        // دورٌ مخصَّص أُنشئ من شاشة الأدوار يجب أن يُغلَق أيضًا.
        foreach (Role::where('name', '!=', 'admin')->get() as $role) {
            foreach (self::TRIAL_PERMISSIONS as $permission) {
                if ($role->hasPermissionTo($permission, 'web')) {
                    $role->revokePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::ORIGINAL_GRANTS as $roleName => $permissions) {
            Role::where('name', $roleName)->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
