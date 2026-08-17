<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| الترحيل الجذري المضمون: مكنسة كل دقيقة تلتقط أي طلب توصيل مؤكّد بلا رقم تتبّع فتعيد
| إرساله لشركة التوصيل حتى ينجح — ضمان الوصول دون اعتماد على عامل طابور دائم. آمنة
| ومتوقّفة ذاتيًا حين لا مزوّد توصيل مُفعّل (no-op)، و idempotent (لا تكرار للشحنات).
*/
Schedule::command('shipping:dispatch-pending')->everyMinute()->withoutOverlapping();
// إلغاء الطرود لدى المزوّد للطلبات الملغاة التي تعذّر إلغاؤها — الطرد النشط لطلب ملغى خسارة.
Schedule::command('shipping:cancel-pending')->everyMinute()->withoutOverlapping();

/*
| جدولة عمليات التوصيل (Phase 4.3 / ADR-039) — مفعّلة بالإعداد فقط (config/delivery.php).
*/
if (config('delivery.sync.enabled')) {
    Schedule::command('delivery:sync')->cron(config('delivery.sync.cron'))->withoutOverlapping();
}
if (config('delivery.escalation.enabled')) {
    Schedule::command('delivery:escalate-exceptions')->cron(config('delivery.escalation.cron'))->withoutOverlapping();
}

/*
| جدولة أتمتة التسويق (Phase 6 / ADR-046). آمنة بلا حملات مفعّلة (no-op). الإرسال عبر
| MessagingManager فقط؛ لا رسائل خارجية أثناء الاختبارات (مزوّد وهمي).
*/
Schedule::command('marketing:run-birthdays')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('marketing:run-abandoned-carts')->hourly()->withoutOverlapping();

/*
| سحب الصرف الإعلاني (ADR-052) — مفعّل بالإعداد فقط (config/ads.php)، وآمن بلا ربط
| (المحرّك الافتراضي `null`). يُعيد سحب آخر عدّة أيام لأن أرقام Meta تُراجَع بعد
| نشرها بيومٍ إلى ثلاثة، فالرقم الأوّلي ليس نهائيًّا.
*/
if (config('ads.sync.enabled')) {
    Schedule::command('ads:sync-spend')->cron(config('ads.sync.cron'))->withoutOverlapping();
}

/*
| الطيّار الآلي (ADR-053) — بعد المزامنة، لأن القرار على صرفٍ لم يُسحَب قرارٌ على
| فراغ. مجدولٌ دائمًا وآمنٌ دائمًا: التفعيل من اللوحة لا من هنا، والافتراض مطفأ
| ومحرّك الكتابة `null` — فلا يملك أصلًا صلاحية إنفاق مال.
*/
Schedule::command('ads:autopilot')->cron(config('ads.autopilot.cron'))->withoutOverlapping();
