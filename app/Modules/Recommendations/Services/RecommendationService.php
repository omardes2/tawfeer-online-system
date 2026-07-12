<?php

namespace App\Modules\Recommendations\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Recommendations\Models\ProductRecommendation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * محرّك التوصيات القائم على القواعد (Phase 6 / ADR-045).
 *
 * يعتمد على **الكتالوج وتاريخ الطلبات فقط** (منطق موثوق أولًا) مع نقطة امتداد لمزوّد
 * ذكاء اصطناعي مستقبلًا. **لا يوصي أبدًا بمنتج غير مفعّل/محذوف/غير متوفّر/نافد** (المستودع
 * الرئيس الوحيد). يحترم التوصيات اليدوية (include) والاستثناءات (exclude) من لوحة الإدارة،
 * ويسجّل **المصدر والسبب** على كل منتج (سمات عابرة) لتتبّع الأداء.
 */
class RecommendationService
{
    /** ربط دوال العقد بأنواع القواعد المخزّنة (product_recommendations.type). */
    private const RULE_TYPES = [
        'related' => 'related',
        'frequentlyBoughtTogether' => 'fbt',
        'crossSell' => 'cross_sell',
        'upsell' => 'upsell',
        'bundles' => 'complementary',
    ];

    /** أساس المنتجات القابلة للعرض: مفعّل + ظاهر + غير محذوف + متوفّر في المخزون. */
    public function availableBase(): Builder
    {
        return Product::query()->active()->visible()
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_variants')
                    ->join('inventory_stocks', 'inventory_stocks.variant_id', '=', 'product_variants.id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->whereRaw('inventory_stocks.on_hand - inventory_stocks.reserved > 0');
            })
            ->with(['primaryImage', 'defaultVariant', 'brand']);
    }

    /** مميّز (حقيقة كتالوجية). */
    public function featured(int $limit = 8): Collection
    {
        return $this->decorate(
            $this->availableBase()->where('is_featured', true)->latest('id')->limit($limit)->get(),
            'catalog', 'featured'
        );
    }

    /** وصل حديثًا (الأحدث). */
    public function newArrivals(int $limit = 8): Collection
    {
        return $this->decorate(
            $this->availableBase()->latest('id')->limit($limit)->get(),
            'catalog', 'new_arrival'
        );
    }

    /** الأكثر مبيعًا / الرائج — تجميع كميات مبيعة (آخر 90 يومًا). */
    public function bestSellers(int $limit = 8): Collection
    {
        $ids = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.created_at', '>=', now()->subDays(90))
            ->groupBy('product_variants.product_id')
            ->orderByRaw('SUM(order_items.qty) DESC')
            ->limit($limit * 3)
            ->pluck('product_variants.product_id');

        return $this->decorate($this->byIds($ids, $limit), 'orders', 'best_seller');
    }

    /** منتجات مشابهة: نفس الفئة (ثم العلامة) عدا المنتج نفسه. */
    public function related(Product $product, int $limit = 8): Collection
    {
        $base = $this->availableBase()
            ->where('id', '!=', $product->id)
            ->where(fn ($q) => $q->where('category_id', $product->category_id)
                ->when($product->brand_id, fn ($qq) => $qq->orWhere('brand_id', $product->brand_id)))
            ->latest('id')->limit($limit)->get();

        return $this->withManual($product, 'related', $base, 'catalog', 'same_category');
    }

    /** يُشترى معًا كثيرًا: منتجات ظهرت في نفس طلبات هذا المنتج. */
    public function frequentlyBoughtTogether(Product $product, int $limit = 8): Collection
    {
        $ids = $this->coPurchasedIds($product, $limit * 3);
        $base = $this->byIds($ids, $limit);

        return $this->withManual($product, 'frequentlyBoughtTogether', $base, 'orders', 'bought_together');
    }

    /** بيع تكميلي (cross-sell): فئات مختلفة اشتُريت مع هذا المنتج. */
    public function crossSell(Product $product, int $limit = 8): Collection
    {
        $ids = $this->coPurchasedIds($product, $limit * 3, differentCategory: $product->category_id);
        $base = $this->byIds($ids, $limit);

        return $this->withManual($product, 'crossSell', $base, 'orders', 'cross_sell');
    }

    /** ترقية (upsell): نفس الفئة بسعر أعلى. */
    public function upsell(Product $product, int $limit = 8): Collection
    {
        $price = (float) ($product->defaultVariant?->retail_price ?? 0);

        // بلا join على المتغيّرات (يُسبّب التباس أعمدة مع النطاقات) — نستخدم استعلامًا مرتبطًا.
        $base = $this->availableBase()
            ->where('products.id', '!=', $product->id)
            ->where('products.category_id', $product->category_id)
            ->whereExists(function ($q) use ($price) {
                $q->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_default', true)
                    ->where('product_variants.retail_price', '>', $price);
            })
            ->orderByDesc('products.id')
            ->limit($limit)->get();

        return $this->withManual($product, 'upsell', $base, 'catalog', 'higher_tier');
    }

    /** ملحقات مكمّلة / حزم (bundles): يدوية أساسًا، مع رجوع لبيع تكميلي. */
    public function bundles(Product $product, int $limit = 8): Collection
    {
        $base = $this->crossSell($product, $limit);

        return $this->withManual($product, 'bundles', $base, 'orders', 'complementary');
    }

    /** توصيات شخصية بسيطة: من فئات طلبات المستخدم السابقة (بلا تحليل عميق). */
    public function personalized(?int $userId, int $limit = 8): Collection
    {
        if (! $userId) {
            return $this->bestSellers($limit);
        }

        // الطلبات مربوطة بالعميل (orders.customer_id)؛ نربط المستخدم بعملائه (customers.user_id).
        $customerIds = DB::table('customers')->where('user_id', $userId)->pluck('id');
        if ($customerIds->isEmpty()) {
            return $this->bestSellers($limit);
        }

        $categoryIds = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereIn('orders.customer_id', $customerIds)
            ->where('orders.status', '!=', 'cancelled')
            ->pluck('products.category_id')->unique()->filter()->values();

        if ($categoryIds->isEmpty()) {
            return $this->bestSellers($limit);
        }

        $purchasedProductIds = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->whereIn('orders.customer_id', $customerIds)
            ->pluck('product_variants.product_id');

        $base = $this->availableBase()
            ->whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $purchasedProductIds)
            ->latest('id')->limit($limit)->get();

        return $this->decorate($base, 'personalized', 'from_history');
    }

    /**
     * دمج التوصيات اليدوية (include) والاستثناءات (exclude) فوق النتيجة الحسابية.
     * القواعد اليدوية تُقدَّم أولًا (position) وتُستبعد المنتجات المحجوبة والنافدة.
     */
    private function withManual(Product $product, string $method, Collection $algo, string $source, string $reason): Collection
    {
        $ruleType = self::RULE_TYPES[$method] ?? 'related';

        $rules = ProductRecommendation::query()->active()
            ->where('product_id', $product->id)->where('type', $ruleType)
            ->orderBy('position')->get();

        $excludeIds = $rules->where('kind', 'exclude')->pluck('recommended_product_id')->all();
        $includeIds = $rules->where('kind', 'include')->pluck('recommended_product_id');

        // المنتجات اليدوية المضمّنة — مع فلترة التوافر (لا نُظهر نافدًا/محذوفًا حتى لو أُدرج يدويًا).
        $manual = $includeIds->isNotEmpty()
            ? $this->decorate($this->byIds($includeIds, $includeIds->count()), 'manual', 'admin_pick')
            : collect();

        // النتيجة الحسابية بعد استبعاد المحجوب واليدوي (تفاديًا للتكرار) ونفس المنتج.
        $manualIds = $manual->pluck('id')->all();
        $filtered = $algo->reject(fn ($p) => in_array($p->id, $excludeIds, true)
            || in_array($p->id, $manualIds, true)
            || $p->id === $product->id)->values();

        $filtered = $this->decorate($filtered, $source, $reason);

        return $manual->concat($filtered)->take(max($manual->count(), 8));
    }

    /** معرّفات منتجات اشتُريت في نفس طلبات المنتج. */
    private function coPurchasedIds(Product $product, int $limit, ?int $differentCategory = null): Collection
    {
        $orderIds = DB::table('order_items')
            ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->where('product_variants.product_id', $product->id)
            ->pluck('order_items.order_id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return DB::table('order_items')
            ->join('product_variants', 'order_items.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereIn('order_items.order_id', $orderIds)
            ->where('product_variants.product_id', '!=', $product->id)
            ->when($differentCategory, fn ($q) => $q->where('products.category_id', '!=', $differentCategory))
            ->groupBy('product_variants.product_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->pluck('product_variants.product_id');
    }

    /** تحميل منتجات متوفّرة من قائمة معرّفات مع الحفاظ على الترتيب. */
    private function byIds(Collection $ids, int $limit): Collection
    {
        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $products = $this->availableBase()->whereIn('products.id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $products->get($id))->filter()->take($limit)->values();
    }

    /** يضبط سمات عابرة للمصدر والسبب على كل منتج (تتبّع الأداء — لا يُخزَّن). */
    private function decorate(Collection $products, string $source, string $reason): Collection
    {
        return $products->each(function ($p) use ($source, $reason) {
            $p->recommendation_source = $source;
            $p->recommendation_reason = $reason;
        });
    }
}
