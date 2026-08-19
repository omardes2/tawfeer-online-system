<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع نافذة الحكم وخفض عتبة الطلبات: ٣ أيام/١٠ طلبات ⇐ ٧ أيام/٥ طلبات.
 *
 * العتبة الأولى صُمّمت لحماية القرار من الضجيج، لكنّها في متجرٍ يوزّع ميزانيته
 * على أصنافٍ كثيرة حجبت الحكم عن أغلب الصفوف: صنفٌ يبيع ٤ قطع في ثلاثة أيام لا
 * يبلغ العشرة أبدًا، فتبقى شارته «بيانات غير كافية» إلى الأبد ويبقى صاحب المتجر
 * بلا قرارٍ على صرفٍ يدفعه فعلًا. صمتٌ دائم ليس حمايةً.
 *
 * والعلاج بالنافذة قبل العتبة: سبعة أيام تجمع ضِعف الطلبات تقريبًا، فتُخفَّض
 * العتبة إلى خمسة وقد بقيت العيّنة أكبر ممّا كانت (٥ في ٧ أيام أمتن من ١٠ في ٣).
 *
 * ولا يُدهَس ما ضبطه المستخدم: يُغيَّر الرقم إن كان ما يزال على الافتراضي الأول
 * وحده.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: int, 2: int}> */
    private const CHANGES = [
        // [المفتاح، القيمة القديمة المتوقَّعة، القيمة الجديدة]
        ['ads.window_days', 3, 7],
        ['ads.min_orders', 10, 5],
    ];

    public function up(): void
    {
        $this->apply(fn (array $c) => [$c[1], $c[2]]);
    }

    public function down(): void
    {
        $this->apply(fn (array $c) => [$c[2], $c[1]]);
    }

    /** @param  callable(array{0: string, 1: int, 2: int}): array{0: int, 1: int}  $direction */
    private function apply(callable $direction): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::CHANGES as $change) {
            [$expected, $target] = $direction($change);

            if ((int) Settings::get($change[0], $expected) === $expected) {
                Settings::set($change[0], $target, 'ads', 'integer');
            }
        }
    }
};
