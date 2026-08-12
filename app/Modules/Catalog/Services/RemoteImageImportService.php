<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * سحب صور المنتجات من موقع خارجي (الموقع القديم) وربطها بمنتجات النظام.
 *
 * المصدر ملف CSV بعمودين: اسم الصنف (أو كوده) ورابط الصورة. لكل سطر تُنزَّل الصورة
 * ثم تمرّ بـ`ProductImageService` نفسها التي يستخدمها الرفع اليدوي — فتُحوَّل إلى WebP
 * وتُصغَّر حسب دورها، ولا يوجد مسار ثانٍ للصور يختلف سلوكه.
 *
 * المطابقة بالكود (SKU) أولًا لأنه فريد، ثم بالاسم الكامل، ثم بالاسم بعد تطبيعه
 * (تجاهل المسافات المكرّرة والتشكيل وصور الألف/الياء) — فاختلاف «أ/ا» أو «ة/ه» بين
 * الموقعين لا يُفشل المطابقة.
 */
class RemoteImageImportService
{
    /** ترويسات العمودين المقبولة. */
    private const HEADERS = [
        'product' => ['اسم الصنف', 'الصنف', 'اسم المنتج', 'المنتج', 'الكود', 'sku', 'name', 'product'],
        'url' => ['رابط الصورة', 'الصورة', 'رابط', 'image', 'image_url', 'url'],
    ];

    /** أقصى حجم للصورة المُنزَّلة (بايت) — حارس ضد ملفات ضخمة. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly ProductImageService $images) {}

    /**
     * تحليل ملف الربط: كل سطر ⇒ [المنتج المطابق، رابط الصورة] أو سبب التخطّي.
     *
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['rows' => [], 'errors' => [__('تعذّر فتح الملف.')]];
        }

        if (fgets($handle, 4) !== "\xEF\xBB\xBF") {
            rewind($handle); // تخطّي BOM من Excel.
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return ['rows' => [], 'errors' => [__('الملف فارغ.')]];
        }

        $map = $this->mapColumns($header);
        if (! isset($map['product'], $map['url'])) {
            fclose($handle);

            return ['rows' => [], 'errors' => [__('الملف يجب أن يحوي عمودَي «اسم الصنف» و«رابط الصورة».')]];
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            $key = trim((string) ($raw[$map['product']] ?? ''));
            $url = trim((string) ($raw[$map['url']] ?? ''));

            if ($key === '' && $url === '') {
                continue; // سطر فارغ.
            }
            if ($key === '' || $url === '') {
                $errors[] = __('سطر :l: اسم الصنف أو رابط الصورة مفقود.', ['l' => $line]);

                continue;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = __('سطر :l: رابط غير صالح (:u).', ['l' => $line, 'u' => Str::limit($url, 60)]);

                continue;
            }

            $product = $this->matchProduct($key);
            if ($product === null) {
                $errors[] = __('سطر :l: لا يوجد صنف مطابق لـ«:n».', ['l' => $line, 'n' => $key]);

                continue;
            }

            $rows[] = ['line' => $line, 'product' => $product, 'key' => $key, 'url' => $url];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * تنزيل صورة السطر وربطها بالمنتج. الفشل يخصّ سطره ولا يُسقط بقية الاستيراد.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{attached: int, failed: array<int, string>}
     */
    public function import(array $rows, bool $skipIfHasImages = true): array
    {
        $attached = 0;
        $failed = [];

        foreach ($rows as $row) {
            $product = $row['product'];

            if ($skipIfHasImages && $product->images()->exists()) {
                $failed[] = __('«:n» له صور مسبقًا — تُخطّي.', ['n' => $product->name]);

                continue;
            }

            try {
                $file = $this->download($row['url']);
                $this->images->store($product, $file, ['is_primary' => true]);
                @unlink($file->getRealPath());
                $attached++;
            } catch (\Throwable $e) {
                $failed[] = __('سطر :l: تعذّر تنزيل صورة «:n» — :m', [
                    'l' => $row['line'], 'n' => $product->name, 'm' => Str::limit($e->getMessage(), 120),
                ]);
            }
        }

        return ['attached' => $attached, 'failed' => $failed];
    }

    /** تنزيل الرابط إلى ملف مؤقّت والتحقّق أنه صورة فعلًا. */
    private function download(string $url): UploadedFile
    {
        try {
            $response = Http::timeout(30)->withOptions(['stream' => false])->get($url);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(__('تعذّر الاتصال بالرابط.'), 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(__('الخادم أرجع الرمز :c.', ['c' => $response->status()]));
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException(__('الملف فارغ أو أكبر من الحدّ المسموح.'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $body);

        // التحقّق من كونها صورة حقيقية (لا صفحة خطأ HTML بامتداد صورة).
        $info = @getimagesize($tmp);
        if ($info === false) {
            @unlink($tmp);
            throw new \RuntimeException(__('المحتوى ليس صورة صالحة.'));
        }

        return new UploadedFile($tmp, basename(parse_url($url, PHP_URL_PATH) ?: 'image'), $info['mime'], null, true);
    }

    /** مطابقة المنتج: بالكود ثم بالاسم ثم بالاسم بعد التطبيع. */
    private function matchProduct(string $key): ?Product
    {
        $product = Product::where('sku', $key)->first() ?? Product::where('name', $key)->first();
        if ($product) {
            return $product;
        }

        $normalized = $this->normalize($key);

        return Product::all(['id', 'name', 'sku'])
            ->first(fn (Product $p) => $this->normalize($p->name) === $normalized);
    }

    /** تطبيع الاسم العربي: توحيد الألف/الياء/التاء المربوطة وإزالة التشكيل والمسافات الزائدة. */
    private function normalize(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value); // تشكيل وتطويل
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $value = preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $title = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $title)));
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($title, array_map('mb_strtolower', $aliases), true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }
}
