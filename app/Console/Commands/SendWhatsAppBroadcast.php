<?php

namespace App\Console\Commands;

use App\Modules\Marketing\Models\CampaignTemplate;
use App\Modules\Marketing\Services\ContactBroadcastService;
use Illuminate\Console\Command;

/**
 * إرسال دفعةٍ من قالبٍ معتمَد إلى جهات الاتصال.
 *
 * **يدويّ لا مجدول عمدًا.** الإرسال المجمّع قرارُ إنسانٍ يراقب: يُشغّل دفعة،
 * ينتظر ساعةً يقرأ فيها التسليم والفشل، ثم يقرّر التوسّع. وجدولةٌ تُطلقه كل
 * ليلة تُحوّل قائمةً من خمسة عشر ألفًا إلى موجة حجبٍ لا يوقفها أحد قبل الصباح.
 */
class SendWhatsAppBroadcast extends Command
{
    protected $signature = 'whatsapp:broadcast
                            {template : معرّف القالب}
                            {--limit= : حجم الدفعة (الافتراضي من الإعداد)}
                            {--customers-only : الذين اشتروا فعلًا وحدهم}';

    protected $description = 'إرسال دفعة من قالب واتساب معتمَد إلى جهات الاتصال المسموح بمراسلتها';

    public function handle(ContactBroadcastService $broadcast): int
    {
        $template = CampaignTemplate::find((int) $this->argument('template'));

        if (! $template) {
            $this->error('قالب غير موجود.');

            return self::FAILURE;
        }

        $summary = $broadcast->run($template, array_filter([
            'limit' => $this->option('limit') ? (int) $this->option('limit') : null,
            'customers_only' => (bool) $this->option('customers-only'),
        ]));

        $this->info(sprintf(
            'أُرسل: %d · فشل: %d · متخطّى: %d · متبقٍّ اليوم: %d',
            $summary['sent'], $summary['failed'], $summary['skipped'], $summary['remaining_today'],
        ));

        if ($summary['reason']) {
            $this->warn($summary['reason']);
        }

        // الفشل يُعيد رمزًا غير صفري كي تُلاحظه الأتمتة لو شُغّل من سكربت.
        return $summary['aborted'] ? self::FAILURE : self::SUCCESS;
    }
}
