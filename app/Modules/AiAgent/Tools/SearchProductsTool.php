<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Store\Services\CartService;

/**
 * البحث عن أصناف يبيعها الوكيل.
 *
 * لا تُرجع إلّا ما **كُتبت له معرفةٌ بيعية جاهزة**: صنفٌ بلا `is_ready` لا
 * يعرف الوكيل كيف يبيعه، فيرتجل — وارتجالُه في الاعتراض هو ما يُفقد الثقة.
 * والصنف غير الجاهز يبقى للموظفة عبر `escalate_to_human`.
 */
class SearchProductsTool implements ToolContract
{
    private const MAX = 5;

    public function __construct(private readonly CartService $carts) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'ابحث عن منتجات المتجر بكلمةٍ من كلام الزبون. استخدمها قبل ذكر أي منتج.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'كلمة البحث كما قالها الزبون'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = min(self::MAX, max(1, (int) ($arguments['limit'] ?? 3)));

        if ($query === '') {
            return ['products' => []];
        }

        $readyIds = ProductKnowledge::ready()->pluck('product_id');

        $products = Product::query()->active()
            ->whereIn('id', $readyIds)
            ->where('visibility', 'visible')
            ->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('sku', 'like', '%'.$query.'%'))
            ->with(['defaultVariant.inventoryStocks', 'variants.inventoryStocks'])
            ->limit($limit)
            ->get();

        return ['products' => $products->map(fn (Product $p) => [
            'product_id' => $p->id,
            'name' => $p->name,
            // السعر من الكتالوج نفسه لا من حسابٍ هنا؛ والسعر النهائي من `get_price`.
            'price_from' => number_format($this->priceFrom($p), 2, '.', ''),
            'in_stock' => $this->inStock($p),
        ])->all()];
    }

    private function priceFrom(Product $product): float
    {
        $prices = $product->variants
            ->map(fn ($v) => $this->carts->sellingPrice($v))
            ->filter(fn (float $p) => $p > 0);

        return $prices->isEmpty() ? 0.0 : (float) $prices->min();
    }

    private function inStock(Product $product): bool
    {
        return $product->variants->contains(fn ($v) => $this->carts->availableQty($v) > 0);
    }
}
