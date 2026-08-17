<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات الطيّار الآلي — كلّها من اللوحة لا من ملف البيئة (المبدأ 8).
 *
 * السقف اليومي بالذات: هو الرقم الذي طلب صاحب العمل التحكّم فيه بنفسه، وقيمةٌ
 * في `.env` تعني نشرًا على الخادم لكل تعديل — أي أن السقف الحقيقي سيبقى ما
 * ضُبط أوّل مرّة.
 *
 * والافتراضات كلّها في الجانب الآمن: الطيّار **مطفأ**، ووضعُه «اقتراح» لا
 * «فرملة»، وسقفه صفر (أي: بلا سقف مضبوط، فلا تنفيذ). ثلاثة أقفال — يفتحها
 * صاحب العمل واحدًا واحدًا وهو يقرأ سجلّ القرارات.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string}> */
    private const DEFAULTS = [
        // مفتاح الإطفاء الرئيسي.
        ['ads.autopilot.enabled', false, 'boolean'],

        /*
        | suggest: يكتب القرارات ولا يلمس المنصّة — للمراجعة قبل منحه المال.
        | brake:   ينفّذ الإيقاف والتخفيض وحدهما. لا يزيد ولا يُنشئ في أي وضع.
        */
        ['ads.autopilot.mode', 'suggest', 'string'],

        /*
        | السقف اليومي بعملة الحساب الإعلاني: مجموع الميزانيات اليومية للمجموعات
        | التي يديرها الطيّار لا يتجاوزه. وصفرٌ معناه «لم يُضبط» فيمتنع التنفيذ —
        | لا «بلا حدّ».
        */
        ['ads.autopilot.daily_cap', 0, 'double'],

        /*
        | أقصى نسبة تخفيضٍ في المرّة. تجاوزُ ~20% يُعيد المجموعة إلى مرحلة التعلّم
        | لدى المنصّة، فتخفيضٌ يوميّ عنيف يُبقيها في التعلّم أبدًا — أداءٌ أسوأ
        | ممّا لو لم يتدخّل أحد.
        */
        ['ads.autopilot.max_decrease_pct', 20, 'integer'],

        // أيام التهدئة بعد كل تعديل ميزانية — للسبب نفسه.
        ['ads.autopilot.cooldown_days', 3, 'integer'],

        /*
        | أدنى ميزانية يومية مقبولة. ما ينزل تحتها يُوقَف بدل أن يُخفَّض: ميزانيةٌ
        | دون حدّ المنصّة تُرفض، ودونها بقليل لا تشتري بياناتٍ يُحكَم عليها.
        */
        ['ads.autopilot.min_budget', 5, 'double'],
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
