<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * إزالة الطيّار الآلي للإعلانات (كان ADR-053) — بقرار المالك.
 *
 * ملفّات الترحيل التي أنشأت هذه البنية حُذفت، فالتنصيب الجديد لا يُنشئها أصلًا
 * وهذا الملف يمرّ عليه بلا أثر. ووجودُه لأجل ما رُحّل فعلًا: جدولٌ وعمودٌ
 * وإعداداتٌ وصلاحيات تبقى معلّقةً بلا شيء يستعملها، فتظهر في شاشة الأدوار وفي
 * المخطّط كأنها ميزةٌ قائمة.
 *
 * وكل خطوة مشروطة بوجود هدفها: الترحيل يجب أن يمرّ على قاعدةٍ لم تر تلك البنية
 * قطّ كما يمرّ على قاعدةٍ رأتها.
 *
 * ولا يُلمَس `ad_external_maps.parent_external_id` — أُضيف مع الطيّار لكنّ نسبة
 * الطلبات الإلكترونية (ADR-054) تقوم عليه: منه تعرف حملةَ المجموعة الإعلانية،
 * ومنها الصفحة.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const SETTINGS = [
        'ads.autopilot.enabled',
        'ads.autopilot.mode',
        'ads.autopilot.daily_cap',
        'ads.autopilot.max_decrease_pct',
        'ads.autopilot.cooldown_days',
        'ads.autopilot.min_budget',
    ];

    /** @var array<int, string> */
    private const PERMISSIONS = ['marketing.autopilot.view', 'marketing.autopilot.manage'];

    public function up(): void
    {
        Schema::dropIfExists('ad_autopilot_decisions');

        if (Schema::hasColumn('ad_channels', 'autopilot_enabled')) {
            Schema::table('ad_channels', function (Blueprint $table) {
                $table->dropColumn('autopilot_enabled');
            });
        }

        if (Schema::hasTable('settings')) {
            foreach (self::SETTINGS as $key) {
                Settings::forget($key);
            }
        }

        if (Schema::hasTable('permissions')) {
            // الحذف يُسقط ارتباطها بالأدوار معه، فلا تبقى صلاحيةٌ يتيمة.
            Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * لا تراجع.
     *
     * إعادة البناء ليست إعادة عمود: هي شيفرةٌ حُذفت من المستودع. واسترجاعها من
     * تاريخ Git لا من هنا.
     */
    public function down(): void
    {
        // بلا أثر عمدًا.
    }
};
