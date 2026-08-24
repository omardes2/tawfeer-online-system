<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Store\Services\CartService;

/**
 * تفاصيل صنفٍ ومعرفتُه البيعية ومتغيّراته.
 *
 * تُرجع **المعرفة البيعية** حين تُوجد: نقاط البيع والاعتراضات والأسئلة الشائعة
 * هي ما يبيع، والمواصفة وحدها لا تُقنع أحدًا.
 *
 * وحين لا تُوجد **لا تُرفض الأداة**، بل يُعطى الوكيل ما يعرفه الكتالوج فعلًا:
 * الوصف والمتغيّرات والتوفّر. كان الردّ سابقًا أمرًا بالتحويل، فأسكت الوكيل عن
 * ١٥١ صنفًا من ١٥٢. والحدّ الآن على **مصدر الكلام** لا على وجوده: `is_ready`
 * تبقى في الردّ ليعرف النموذج أنه بلا نقاط بيعٍ مكتوبة فيلزم الوصف حرفيًّا ولا
 * ينسب للصنف ما ليس فيه.
 */
class GetProductDetailsTool implements ToolContract
{
    public function __construct(private readonly CartService $carts) {}

    public function name(): string
    {
        return 'get_product_details';
    }

    public function description(): string
    {
        return 'اقرأ تفاصيل صنفٍ ونقاط بيعه والردود على اعتراضاته ومتغيّراته المتاحة.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['product_id' => ['type' => 'integer']],
            'required' => ['product_id'],
        ];
    }

    /**
     * وصف الصنف كما يقرأه الوكيل.
     *
     * الوصف المختصر أولًا فهو المكتوب للزبون، والمطوّل يليه. والوسوم تُزال:
     * الوصف مُدخَل بمحرّرٍ غنيّ، و`<p>` و`<strong>` تصل إلى واتساب حرفيًّا إن
     * نسخها النموذج.
     */
    private function describe(Product $product): string
    {
        $parts = array_filter([
            trim(strip_tags((string) $product->short_description)),
            trim(strip_tags((string) $product->description)),
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', implode(' — ', $parts)) ?? '');

        return $text === '' ? 'لا يوجد وصف مكتوب لهذا الصنف.' : $text;
    }

    public function handle(array $arguments): array
    {
        $product = Product::with(['variants.attributeValues', 'variants.inventoryStocks'])
            ->find((int) ($arguments['product_id'] ?? 0));

        if ($product === null) {
            return ['error' => 'not_found', 'message' => 'الصنف غير موجود.'];
        }

        $knowledge = ProductKnowledge::where('product_id', $product->id)->first();
        $ready = $knowledge !== null && $knowledge->is_ready;

        $details = [
            'product_id' => $product->id,
            'name' => $product->name,
            // وصف الكتالوج **دائمًا**: هو كلّ ما يملكه الوكيل عن صنفٍ بلا معرفة،
            // وهو مرجعُ الصدق للصنف الذي له معرفة.
            'description' => $this->describe($product),
            'is_ready' => $ready,
            'variants' => $product->variants->map(fn ($v) => [
                'variant_id' => $v->id,
                'label' => $v->attributeValues->isNotEmpty() ? $v->optionLabel() : $product->name,
                'available_qty' => (string) $this->carts->availableQty($v),
            ])->all(),
        ];

        if (! $ready) {
            // ليست رسالة خطأ بل حدُّ صلاحية: بِعْ بما في الوصف، ولا تخترع.
            $details['note'] = 'لا نقاط بيع مكتوبة لهذا الصنف. التزم بالوصف أعلاه'
                .' حرفيًّا، ولا تنسب له ميزةً غير مذكورة فيه. إن سأل الزبون عمّا'
                .' لا يجيب عنه الوصف، حوّل إلى موظفة.';

            return $details;
        }

        return $details + [
            'selling_points' => $knowledge->selling_points ?? [],
            'use_cases' => $knowledge->use_cases ?? [],
            'objections' => $knowledge->objections ?? [],
            'faq' => $knowledge->faq ?? [],
            'tone_notes' => $knowledge->tone_notes,
        ];
    }
}
