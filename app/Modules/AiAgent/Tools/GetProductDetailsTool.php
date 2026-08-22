<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Store\Services\CartService;

/**
 * تفاصيل صنفٍ ومعرفتُه البيعية ومتغيّراته.
 *
 * تُرجع **المعرفة البيعية** لا المواصفات وحدها: نقاط البيع والاعتراضات
 * والأسئلة الشائعة هي ما يبيع، والمواصفة وحدها لا تُقنع أحدًا.
 *
 * وصنفٌ بلا معرفةٍ جاهزة تُرجع له `is_ready = false` صراحةً، والبرومبت يأمر
 * بالتحويل عندها — فالصمت أفضل من كلامٍ مخترَع باسم الشركة.
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

    public function handle(array $arguments): array
    {
        $product = Product::with(['variants.attributeValues', 'variants.inventoryStocks'])
            ->find((int) ($arguments['product_id'] ?? 0));

        if ($product === null) {
            return ['error' => 'not_found', 'message' => 'الصنف غير موجود.'];
        }

        $knowledge = ProductKnowledge::where('product_id', $product->id)->first();

        if ($knowledge === null || ! $knowledge->is_ready) {
            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'is_ready' => false,
                'message' => 'لا توجد معرفة بيعية جاهزة لهذا الصنف — حوّل المحادثة إلى موظفة.',
            ];
        }

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'is_ready' => true,
            'selling_points' => $knowledge->selling_points ?? [],
            'use_cases' => $knowledge->use_cases ?? [],
            'objections' => $knowledge->objections ?? [],
            'faq' => $knowledge->faq ?? [],
            'tone_notes' => $knowledge->tone_notes,
            'variants' => $product->variants->map(fn ($v) => [
                'variant_id' => $v->id,
                'label' => $v->attributeValues->isNotEmpty() ? $v->optionLabel() : $product->name,
                'available_qty' => (string) $this->carts->availableQty($v),
            ])->all(),
        ];
    }
}
