<?php

namespace App\Support\Integrations\Shipping;

use App\Support\Contracts\Shipping\GeographySyncProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver مزامنة جغرافيا Opost (المبدأ 13، ADR-027). كل منطق Opost محصور هنا.
 * يسحب المدن (وأسعارها إن توفّرت) والمناطق من API عبر توكن في .env.
 *
 * ملاحظة: أسماء حقول Opost قد تختلف قليلًا؛ التطبيع أدناه متسامح (يجرّب عدّة مفاتيح)
 * ويُطبَّع إلى مفاتيح موحّدة يستهلكها DeliveryRateSyncService.
 */
class OpostGeographySyncProvider implements GeographySyncProviderInterface
{
    private function client(?string $token)
    {
        return Http::withToken((string) $token)
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) config('services.opost.base_url', 'https://opost.ps/api'), '/'));
    }

    /**
     * GET مع تجديد تلقائي للتوكن عند 401 (OAuth2 قصير الصلاحية).
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = [])
    {
        $auth = app(OpostTokenProvider::class);
        $res = $this->client($auth->token())->get($path, $query);
        if ($res->status() === 401) {
            // توكن منتهٍ ⇒ أعد المصادقة مرّة واحدة وأعد المحاولة.
            $res = $this->client($auth->token(true))->get($path, $query);
        }

        return $res;
    }

    /** استخراج مصفوفة السجلّات من مغلّفات شائعة ({data:[...]} أو [...] أو {cities:[...]}). */
    private function rows(array $json, string $key): array
    {
        foreach ([$key, 'data', 'results', 'items'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return $json[$k];
            }
        }

        return array_is_list($json) ? $json : [];
    }

    private function pick(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return $row[$k];
            }
        }

        return $default;
    }

    public function pullGovernorates(): iterable
    {
        return []; // Opost يعتمد قائمة مدن مسطّحة.
    }

    /**
     * سحب المدن. يتجاهل معرّف المحافظة (قائمة Opost مسطّحة).
     *
     * @return iterable<array<string, mixed>>
     */
    public function pullCities(string $governorateExternalId = ''): iterable
    {
        try {
            $res = $this->get('/resources/cities');
            if (! $res->successful()) {
                Log::warning('Opost cities sync failed', ['status' => $res->status()]);

                return [];
            }
            $out = [];
            foreach ($this->rows($res->json() ?? [], 'cities') as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $out[] = [
                    'external_id' => (string) $this->pick($row, ['id', 'city_id', 'code', 'external_id']),
                    'name' => (string) $this->pick($row, ['name', 'name_ar', 'city', 'title'], ''),
                    'name_en' => $this->pick($row, ['name_en', 'english_name', 'name_english']),
                    // الأسعار إن أرجعها المزوّد (وإلا null ⇒ تُدار محليًا).
                    'delivery_fee' => $this->pick($row, ['delivery_fee', 'delivery_cost', 'price', 'shipping_cost', 'cost']),
                    'return_fee' => $this->pick($row, ['return_fee', 'return_cost', 'rejection_cost']),
                    'currency' => $this->pick($row, ['currency', 'currency_code']),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('Opost cities sync error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * سحب المناطق لمدينة (اختياري — لعرض أدقّ).
     *
     * @return iterable<array<string, mixed>>
     */
    public function pullAreas(string $cityExternalId): iterable
    {
        try {
            $res = $this->get('/resources/areas', ['city' => $cityExternalId]);
            if (! $res->successful()) {
                return [];
            }
            $out = [];
            foreach ($this->rows($res->json() ?? [], 'areas') as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $out[] = [
                    'external_id' => (string) $this->pick($row, ['id', 'area_id', 'code']),
                    'city_external_id' => (string) $cityExternalId,
                    'name' => (string) $this->pick($row, ['name', 'name_ar', 'area', 'title'], ''),
                    'name_en' => $this->pick($row, ['name_en', 'english_name']),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('Opost areas sync error: '.$e->getMessage());

            return [];
        }
    }

    public function name(): string
    {
        return 'opost';
    }
}
