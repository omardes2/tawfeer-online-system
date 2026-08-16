<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات «الميزانية اليومية»: سعر الصرف الافتراضي وعتبات الحكم.
 *
 * العتبات في الإعدادات لا في الكود (المبدأ 9): هي أرقام عملٍ تتغيّر بتغيّر
 * هامش الربح، ومصدرُها حسابٌ من بيانات المتجر — ربحٌ ~72 ₪ على الطلب المُدخَل
 * (بعد ~5% مرتجعات)، فالتعادل عند ~72 ₪ لتكلفة الطلب. عتبة الإيقاف عند 60
 * تترك هامش أمانٍ تحت التعادل، وهو مطلوب لأن الاحتساب على الطلبات المُدخَلة
 * لا المسلَّمة متفائلٌ بطبعه.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string}> */
    private const DEFAULTS = [
        // سعر صرف الدولار الافتراضي عند إدخال الصرف — يُخزَّن مع كل صفّ على حدة.
        ['ads.usd_rate', 3.7, 'double'],
        // نافذة الحكم بالأيام: تستوعب تأخّر تحوّل المحادثة إلى طلب.
        ['ads.window_days', 3, 'integer'],
        // أقلّ عدد طلبات قبل إصدار حكم — دونه الرقم ضجيجٌ لا دلالة.
        ['ads.min_orders', 10, 'integer'],
        ['ads.cpa_increase_below', 30, 'integer'],
        ['ads.cpa_hold_below', 45, 'integer'],
        ['ads.cpa_reduce_below', 60, 'integer'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::DEFAULTS as [$key, $value, $type]) {
            // قيمةٌ ضبطها المستخدم لا تُدهَس: الافتراضي للتنصيب الأول لا غير.
            if (! Settings::has($key)) {
                Settings::set($key, $value, 'ads', $type);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::DEFAULTS as [$key]) {
            Settings::forget($key);
        }
    }
};
