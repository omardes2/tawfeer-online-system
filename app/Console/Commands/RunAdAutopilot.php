<?php

namespace App\Console\Commands;

use App\Modules\Marketing\Services\AdAutopilotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * دورة الطيّار الآلي — مرّة كل صباح، بعد سحب صرف أمس.
 *
 * وهو آمنٌ بلا إعداد: الطيّار مطفأ افتراضيًّا، ومحرّك الكتابة `null`، فيمرّ
 * الأمر بهدوء ولا يفشل ليليًّا على نظامٍ لم تُفتح له الأتمتة بعد.
 */
class RunAdAutopilot extends Command
{
    protected $signature = 'ads:autopilot
                            {--date= : يوم البيانات (الافتراضي: أمس)}
                            {--dry-run : خطّط واعرض بلا أي كتابة إلى المنصّة}';

    protected $description = 'تشغيل الطيّار الآلي: إيقاف الإعلانات الخاسرة وتخفيض ما دون العتبة';

    public function handle(AdAutopilotService $autopilot): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : Carbon::yesterday();

        try {
            $summary = $autopilot->run($day, null, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            Log::error('ads.autopilot.failed', ['message' => $e->getMessage()]);
            $this->error('فشل الطيّار: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $summary['enabled']) {
            $this->line('الطيّار مطفأ — لا شيء ليُفعَل.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'يوم %s | وضع: %s | صفحات: %d | مخطَّط: %d · منفَّذ: %d · موقوف: %d · مخفَّض: %d · متخطّى: %d · فاشل: %d',
            $summary['report_day']->toDateString(),
            $summary['mode'] === 'brake' ? 'فرملة' : 'اقتراح',
            $summary['channels'],
            $summary['planned'], $summary['applied'], $summary['paused'],
            $summary['decreased'], $summary['skipped'], $summary['failed'],
        ));

        foreach ($summary['notes'] as $note) {
            $this->warn('• '.$note);
        }

        if ($summary['cap_breach']) {
            $this->warn(sprintf('• تجاوز السقف اليومي بمقدار %s — أُوقفت الأقلّ ربحًا.', $summary['cap_shortfall']));
        }

        // الملخّص المالي لليوم — هو سبب وجود الطيّار لا أثرٌ جانبي له.
        if ($summary['totals'] !== []) {
            $t = $summary['totals'];
            $this->line(sprintf(
                'طلبات: %d · مبيعات: %s · صرف إعلاني: %s · بعد الإعلان: %s · بعد الثابت: %s',
                $t['orders'], $t['sales'], $t['spend'], $t['profit_after_ads'], $t['operating_profit'],
            ));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
