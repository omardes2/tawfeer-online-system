<?php

namespace App\Console\Commands;

use App\Modules\Marketing\Services\AdSpendSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * سحب الصرف الإعلاني من المنصّة — مرّة كل ليلة.
 *
 * المدى الافتراضي آخر عدّة أيام لا أمسِ وحده: أرقام Meta تُراجَع خلال 24–72 ساعة،
 * فالرقم الأوّلي ليس نهائيًّا. بدون إعادة السحب يتجمّد صرفٌ ناقص ويُبنى عليه حكم.
 *
 * وهو آمنٌ بلا ربط: المحرّك الافتراضي `null` فيمرّ الأمر بهدوء ولا يفشل ليليًّا.
 */
class SyncAdSpend extends Command
{
    protected $signature = 'ads:sync-spend
                            {--days= : عدد الأيام السابقة (الافتراضي من الإعداد)}
                            {--date= : يوم بعينه بدل المدى}';

    protected $description = 'سحب الصرف الإعلاني وعدد المحادثات من منصّة الإعلانات';

    public function handle(AdSpendSyncService $sync): int
    {
        [$from, $to] = $this->range();

        try {
            $summary = $sync->sync($from, $to);
        } catch (Throwable $e) {
            // لا تُسجَّل الرموز: رسالة المزوّد وحدها تكفي للتشخيص.
            Log::error('ads.sync.failed', ['message' => $e->getMessage()]);
            $this->error('فشلت المزامنة: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $summary['configured']) {
            $this->line('منصّة الإعلانات غير مربوطة — لا شيء ليُسحَب (الإدخال اليدوي كما هو).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s → %s | مُستلَم: %d · مكتوب: %d · غير مربوط: %d · تعارض: %d · معرّفات جديدة: %d',
            $from->toDateString(), $to->toDateString(),
            $summary['fetched'], $summary['written'], $summary['unmapped'], $summary['conflicts'], $summary['new_maps'],
        ));

        if ($summary['unmapped'] > 0) {
            $this->warn('صفوفٌ لم تُكتَب لأن حملتها أو مجموعتها غير مربوطة — أكمل الربط من «الإعدادات ← ربط الحملات».');
        }

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        if ($date = $this->option('date')) {
            $day = Carbon::parse($date)->startOfDay();

            return [$day, $day->copy()];
        }

        $days = max(1, (int) ($this->option('days') ?: config('ads.sync.lookback_days', 3)));
        $to = Carbon::yesterday();

        return [$to->copy()->subDays($days - 1), $to];
    }
}
