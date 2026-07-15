<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * حالة تشغيلية مختصرة لمنظومة إرسال الطلبات لشركة التوصيل: مزوّد التوصيل المُفعّل
 * (config) وصحّة طابور الخلفية (تراكم/مهام فاشلة) — لعرض مؤشّر في اللوحة.
 */
class SystemHealth
{
    /** عتبة اعتبار الطابور «متوقّفًا» عند تراكم مهمة جاهزة أقدم من هذا (ثوانٍ). */
    private const STALE_SECONDS = 120;

    /** @return array<string, mixed> */
    public static function delivery(): array
    {
        $provider = (string) config('shipping.provider', 'null');
        $enabled = $provider !== 'null';

        $pending = self::pendingJobs();
        $oldestAge = self::oldestReadyJobAge();
        $failed = self::failedJobs();

        // الطابور صحّي إن لا تراكم، أو التراكم حديث (يُعالَج الآن).
        $queueHealthy = $pending === 0 || ($oldestAge !== null && $oldestAge < self::STALE_SECONDS);

        return [
            'provider' => $provider,
            'enabled' => $enabled,
            'pending' => $pending,
            'oldest_age' => $oldestAge,      // ثوانٍ منذ أقدم مهمة جاهزة، أو null
            'failed' => $failed,
            'queue_healthy' => $queueHealthy,
            'ok' => $enabled && $queueHealthy && $failed === 0,
        ];
    }

    private static function pendingJobs(): int
    {
        // سائق قاعدة البيانات: نعدّ جدول jobs مباشرةً (أدقّ وأثبت). غيره: Queue::size().
        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            try {
                return (int) DB::table('jobs')->count();
            } catch (\Throwable) {
                return 0;
            }
        }

        try {
            return (int) Queue::size();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** عمر أقدم مهمة جاهزة للتنفيذ في الطابور (سائق database فقط) — مؤشّر توقّف العامل. */
    private static function oldestReadyJobAge(): ?int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return null;
        }
        try {
            $oldest = DB::table('jobs')->where('available_at', '<=', time())->min('available_at');

            return $oldest !== null ? max(0, time() - (int) $oldest) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function failedJobs(): int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
