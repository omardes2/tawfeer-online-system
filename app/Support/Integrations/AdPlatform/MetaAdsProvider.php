<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * محرّك Meta Marketing API — قراءة فقط (`ads_read`).
 *
 * نداءٌ واحد على `/{account}/insights` بمستوى المجموعة الإعلانية وتجزئة يومية،
 * فيعطي صفًّا لكل (يوم × مجموعة) فيه المُنفَق وعدد المحادثات — وهما الرقمان
 * اللذان كانا يُنسخان باليد.
 *
 * الرمز رمزُ «مستخدم نظام» لا رمز شخص: رموز الأشخاص تنتهي بتغيير كلمة المرور
 * أو الخروج، ومهمّةٌ ليلية لا يجوز أن تعتمد على جلسة بشر.
 */
class MetaAdsProvider implements AdPlatformProviderInterface
{
    private const BASE = 'https://graph.facebook.com';

    /** حدٌّ للصفحات يمنع دورةً لا تنتهي لو عاد `paging.next` إلى نفسه. */
    private const MAX_PAGES = 50;

    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return filled(config('ads.meta.token')) && filled(config('ads.meta.account_id'));
    }

    public function dailyInsights(Carbon $from, Carbon $to): Collection
    {
        if (! $this->isConfigured()) {
            return collect();
        }

        $account = 'act_'.ltrim((string) config('ads.meta.account_id'), 'act_');
        $url = self::BASE.'/'.config('ads.meta.version').'/'.$account.'/insights';

        $query = [
            'level' => 'adset',
            // التجزئة اليومية هي جوهر الأمر: بلا هذا يعود مجموعُ المدى صفًّا واحدًا.
            'time_increment' => 1,
            'fields' => 'date_start,campaign_id,campaign_name,adset_id,adset_name,spend,actions,account_currency',
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'limit' => 500,
            'access_token' => config('ads.meta.token'),
        ];

        $rows = collect();
        $pages = 0;

        while ($url !== null && $pages < self::MAX_PAGES) {
            $response = Http::timeout((int) config('ads.meta.timeout', 30))
                ->retry(3, 2000, throw: false)
                ->get($url, $query);

            if ($response->failed()) {
                // الرسالة من Meta تفيد التشخيص (رمز منتهٍ، صلاحية ناقصة، حسابٌ
                // خاطئ) — تُسجَّل بلا الرمز نفسه.
                $error = $response->json('error.message') ?? $response->status();

                throw new RuntimeException('تعذّر جلب بيانات الإعلانات من Meta: '.$error);
            }

            foreach ($response->json('data', []) as $row) {
                $rows->push($this->toRow($row));
            }

            // الرابط التالي يحمل معاملاته كاملةً بما فيها الرمز.
            $url = $response->json('paging.next');
            $query = [];
            $pages++;
        }

        if ($pages >= self::MAX_PAGES) {
            Log::warning('ads.meta.paging_capped', ['pages' => $pages]);
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $row */
    private function toRow(array $row): AdInsightRow
    {
        return new AdInsightRow(
            date: (string) ($row['date_start'] ?? ''),
            campaignId: (string) ($row['campaign_id'] ?? ''),
            campaignName: (string) ($row['campaign_name'] ?? ''),
            adsetId: (string) ($row['adset_id'] ?? ''),
            adsetName: (string) ($row['adset_name'] ?? ''),
            spend: (float) ($row['spend'] ?? 0),
            conversations: $this->conversations($row['actions'] ?? []),
            currency: (string) ($row['account_currency'] ?? 'USD'),
        );
    }

    /**
     * عدد المحادثات من مصفوفة الأحداث.
     *
     * Meta تُرجع كل أنواع الأحداث في مصفوفة واحدة؛ نوعُ «المحادثة» في الإعداد لأن
     * تسميته تتغيّر ولأن حسابًا بهدفٍ آخر يحتاج نوعًا مختلفًا.
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function conversations(array $actions): int
    {
        $type = (string) config('ads.meta.conversation_action');

        foreach ($actions as $action) {
            if (($action['action_type'] ?? null) === $type) {
                return (int) ($action['value'] ?? 0);
            }
        }

        return 0;
    }
}
