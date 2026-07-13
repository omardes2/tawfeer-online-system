<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\GenerateBundleRequest;
use App\Http\Requests\Ai\GenerateContentRequest;
use App\Modules\Ai\Services\AiContentService;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * مساعد محتوى المنتجات بالذكاء الاصطناعي (Phase 6 / ADR-044) — متحكّم رفيع.
 *
 * لا يستدعي أي مزوّد مباشرةً؛ يفوّض إلى AiContentService (المزوّد محقون خلف عقد).
 * **يُرجِع اقتراحًا فقط في JSON** — لا يكتب أي حقل منتج ولا ينشر. الموظّف يراجع/يطبّق يدويًا.
 */
class AiContentController extends Controller
{
    public function __construct(private readonly AiContentService $service) {}

    public function generate(GenerateContentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $product = isset($data['product_id']) ? Product::find($data['product_id']) : null;

        // صورة المنتج تُرسَل فقط عند تفعيل التحليل البصري + طلب صريح (المتطلّب الأمني).
        $imageUrl = null;
        if (($data['use_image'] ?? false) && config('ai.vision') && $product) {
            $imageUrl = $product->loadMissing('primaryImage')->primaryImage?->url();
        }

        // نقطة نهاية JSON (خارج api/*): نرجع أخطاء الخدمة (حدّ معدّل/نوع) JSON صراحةً.
        try {
            $result = $this->service->generate(
                type: $data['type'],
                action: $data['action'] ?? 'generate',
                locale: $data['locale'] ?? 'ar',
                inputs: $data['inputs'] ?? [],
                tone: $data['tone'] ?? null,
                product: $product,
                imageUrl: $imageUrl,
                actor: $request->user(),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'suggestion' => $result->content,
            'status' => $result->status,
            'model' => $result->model,
            'total_tokens' => $result->totalTokens(),
            // تذكير صريح للواجهة: المحتوى اقتراح لا يُنشر تلقائيًا.
            'applied' => false,
        ]);
    }

    /**
     * زر «توليد بالذكاء الاصطناعي» — حزمة محتوى منتج كاملة بنداء واحد.
     * **اقتراح فقط في JSON — لا يكتب أي حقل منتج ولا ينشر.** الموظّف يراجع/يعدّل/يحفظ يدويًا.
     */
    public function bundle(GenerateBundleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = isset($data['product_id']) ? Product::find($data['product_id']) : null;

        $imageUrl = null;
        if (($data['use_image'] ?? false) && config('ai.vision') && $product) {
            $imageUrl = $product->loadMissing('primaryImage')->primaryImage?->url();
        }

        $categories = Category::query()->orderBy('name')->get(['id', 'name']);
        $tags = ProductTag::query()->orderBy('name')->get(['id', 'name']);

        try {
            $result = $this->service->generateBundle(
                locale: $data['locale'] ?? 'ar',
                inputs: $data['inputs'] ?? [],
                context: ['categories' => $categories->pluck('name')->all(), 'tags' => $tags->pluck('name')->all()],
                product: $product,
                imageUrl: $imageUrl,
                actor: $request->user(),
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $bundle = json_decode($result->content, true) ?: [];

        return response()->json([
            'fields' => [
                'title' => $bundle['title'] ?? null,
                'title_en' => $bundle['title_en'] ?? null,
                'short_description' => $bundle['short_description'] ?? null,
                'description' => $bundle['description'] ?? null,
                'features' => $bundle['features'] ?? null,
                'specs' => $bundle['specs'] ?? null,
                'seo_title' => $bundle['seo_title'] ?? null,
                'meta_description' => $bundle['meta_description'] ?? null,
                'keywords' => $bundle['keywords'] ?? null,
            ],
            // مطابقة الاقتراح للمعرّفات الموجودة (لا إنشاء تلقائي).
            'category_id' => $this->matchName($bundle['category'] ?? null, $categories),
            'tag_ids' => $this->matchNames($bundle['tags'] ?? [], $tags),
            'status' => $result->status,
            'model' => $result->model,
            'total_tokens' => $result->totalTokens(),
            'applied' => false,
        ]);
    }

    /** أقرب مطابقة نصّية لاسم واحد ضمن مجموعة (id/name). */
    private function matchName(?string $name, $options): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $hit = $options->first(fn ($o) => Str::lower($o->name) === Str::lower($name))
            ?? $options->first(fn ($o) => Str::contains(Str::lower($o->name), Str::lower($name)) || Str::contains(Str::lower($name), Str::lower($o->name)));

        return $hit?->id;
    }

    /** مطابقة قائمة أسماء إلى معرّفات موجودة. @return array<int, int> */
    private function matchNames($names, $options): array
    {
        return collect((array) $names)
            ->map(fn ($n) => $this->matchName(is_string($n) ? $n : '', $options))
            ->filter()->unique()->values()->all();
    }
}
