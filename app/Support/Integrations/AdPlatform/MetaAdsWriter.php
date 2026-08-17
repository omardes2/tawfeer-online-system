<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformWriterInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * محرّك الكتابة إلى Meta Marketing API — صلاحية `ads_management`.
 *
 * ثلاثة قرارات في هذا الملف:
 *
 * 1. **رمزٌ وحسابٌ منفصلان.** `META_ADS_WRITE_TOKEN` و`META_ADS_WRITE_ACCOUNT_ID`،
 *    لا رمز القراءة ولا حسابها. وغيابُ أيٍّ منهما يُبقي المحرّك «غير مضبوط» ولو
 *    كان رمز القراءة حاضرًا — وهذا هو الحاجز كلّه. ولا وراثة ضمنية لحساب القراءة:
 *    كانت ستجعل الطيّار يتصرّف في حساب حملات الرسائل وصاحبُه يظنّه معزولًا.
 *
 * 2. **بلا إعادة محاولة تلقائية على الكتابة.** القراءة تُعاد بلا ضرر، أمّا نداءٌ
 *    كتابةٌ انقطع بعد وصوله فإعادتُه تعني تنفيذه مرّتين. تكرار «أوقف» غير ضارّ،
 *    لكن تكرار تخفيضٍ بنسبة يُنقص الميزانية مرّتين. فالفشل يُبلَّغ ويُعاد القرار
 *    في دورة الغد بعد قراءة الحالة الحيّة من جديد.
 *
 * 3. **المبالغ بالوحدة الصغرى.** Meta تُرجع وتقبل الميزانية بالسنت لا بالدولار،
 *    ومعاملُ التحويل يختلف بالعملة (ينٌّ بلا كسور). خلطُ الوحدتين هنا هو الخطأ
 *    الذي يصرف مئة ضعف بلا أن يرفضه أحد، فالتحويل محصورٌ في دالّتين.
 */
class MetaAdsWriter implements AdPlatformWriterInterface
{
    private const BASE = 'https://graph.facebook.com';

    /** حدّ Meta لعدد المعرّفات في نداء `ids=` الواحد. */
    private const IDS_PER_CALL = 50;

    private const FIELDS = 'id,name,status,effective_status,daily_budget,lifetime_budget,campaign_id';

    private ?string $currency = null;

    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return filled(config('ads.write.token')) && filled(config('ads.write.account_id'));
    }

    public function adSets(array $externalIds): Collection
    {
        $ids = array_values(array_unique(array_filter($externalIds)));

        if ($ids === [] || ! $this->isConfigured()) {
            return collect();
        }

        $currency = $this->accountCurrency();
        $states = collect();

        foreach (array_chunk($ids, self::IDS_PER_CALL) as $chunk) {
            $response = $this->http()->get(self::BASE.'/'.$this->version().'/', [
                'ids' => implode(',', $chunk),
                'fields' => self::FIELDS,
                'access_token' => config('ads.write.token'),
            ]);

            $this->guard($response, 'قراءة حالة المجموعات الإعلانية');

            foreach ($response->json() ?? [] as $id => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $states->put((string) $id, $this->toState($row, $currency));
            }
        }

        return $states;
    }

    public function pause(string $adSetId): void
    {
        $this->write($adSetId, ['status' => 'PAUSED'], 'إيقاف المجموعة الإعلانية');
    }

    public function resume(string $adSetId): void
    {
        $this->write($adSetId, ['status' => 'ACTIVE'], 'تشغيل المجموعة الإعلانية');
    }

    public function setDailyBudget(string $adSetId, float $amount): void
    {
        $minor = $this->toMinor($amount, $this->accountCurrency());

        if ($minor < 1) {
            throw new RuntimeException('ميزانية يومية أقلّ من أصغر وحدة في عملة الحساب.');
        }

        $this->write($adSetId, ['daily_budget' => $minor], 'تعديل الميزانية اليومية');
    }

    /** @param  array<string, mixed>  $payload */
    private function write(string $adSetId, array $payload, string $what): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('الكتابة إلى Meta غير مفعّلة — لا رمز كتابة مضبوط.');
        }

        // بلا `retry`: نداء الكتابة لا يُعاد تلقائيًّا (انظر القرار 2 أعلاه).
        $response = $this->http()->asForm()->post(
            self::BASE.'/'.$this->version().'/'.$adSetId,
            $payload + ['access_token' => config('ads.write.token')],
        );

        $this->guard($response, $what);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('ads.meta.timeout', 30));
    }

    private function version(): string
    {
        return (string) config('ads.meta.version', 'v21.0');
    }

    /**
     * عملة الحساب الإعلاني — تُقرأ مرّة واحدة لكل دورة.
     *
     * لا تُخمَّن ولا تُؤخذ من إعداداتنا: المنصّة تفرضها، والميزانية تُكتب بها.
     */
    private function accountCurrency(): string
    {
        if ($this->currency !== null) {
            return $this->currency;
        }

        $account = 'act_'.ltrim((string) config('ads.write.account_id'), 'act_');

        $response = $this->http()->retry(2, 1000, throw: false)->get(
            self::BASE.'/'.$this->version().'/'.$account,
            ['fields' => 'currency', 'access_token' => config('ads.write.token')],
        );

        $this->guard($response, 'قراءة عملة الحساب الإعلاني');

        return $this->currency = (string) ($response->json('currency') ?: 'USD');
    }

    /** @param  array<string, mixed>  $row */
    private function toState(array $row, string $currency): AdSetState
    {
        return new AdSetState(
            id: (string) ($row['id'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            status: (string) ($row['status'] ?? 'UNKNOWN'),
            effectiveStatus: (string) ($row['effective_status'] ?? $row['status'] ?? 'UNKNOWN'),
            dailyBudget: isset($row['daily_budget']) ? $this->toMajor((float) $row['daily_budget'], $currency) : null,
            lifetimeBudget: isset($row['lifetime_budget']) ? $this->toMajor((float) $row['lifetime_budget'], $currency) : null,
            campaignId: (string) ($row['campaign_id'] ?? ''),
            currency: $currency,
        );
    }

    /** معامل الوحدة الصغرى — 100 في معظم العملات، و1 فيما لا كسور له. */
    private function offset(string $currency): int
    {
        $map = (array) config('ads.write.currency_offsets', []);

        return (int) ($map[strtoupper($currency)] ?? 100);
    }

    private function toMinor(float $amount, string $currency): int
    {
        return (int) round($amount * $this->offset($currency));
    }

    private function toMajor(float $minor, string $currency): float
    {
        return round($minor / $this->offset($currency), 2);
    }

    private function guard(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        // رسالة Meta تفيد التشخيص (صلاحية ناقصة، رمز منتهٍ، ميزانية دون الحدّ
        // الأدنى) — تُنقل كما هي بلا الرمز نفسه.
        $error = $response->json('error.message') ?? ('http '.$response->status());

        throw new RuntimeException('تعذّر '.$what.': '.$error);
    }
}
