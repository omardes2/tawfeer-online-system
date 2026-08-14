<?php

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * طبقة قراءة المتجر (ADR-034): استعلامات العرض العامّة (منتجات معروضة/فعّالة،
 * فلترة/بحث/ترتيب/ترقيم) وتسعير/توافر يُعاد استخدامهما من `CartService` (لا تكرار
 * منطق أعمال). لا كتابة هنا — قراءة فقط.
 */
class StorefrontService
{
    public function __construct(private readonly CartService $carts) {}

    /**
     * قائمة المنتجات المعروضة مع فلترة/بحث/ترتيب/ترقيم.
     *
     * @param  array<string, mixed>  $filters  category/brand (slug)، q، min، max، sort
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::query()->active()->visible()
            ->with(['primaryImage', 'defaultVariant.inventoryStocks', 'variants.inventoryStocks', 'variants.attributeValues', 'brand', 'category']);

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $filters['category']));
        }
        if (! empty($filters['brand'])) {
            $query->whereHas('brand', fn (Builder $q) => $q->where('slug', $filters['brand']));
        }
        if (! empty($filters['q'])) {
            $this->applySearch($query, (string) $filters['q']);
        }
        if (isset($filters['min']) && $filters['min'] !== '') {
            $query->where('retail_price', '>=', (float) $filters['min']);
        }
        if (isset($filters['max']) && $filters['max'] !== '') {
            $query->where('retail_price', '<=', (float) $filters['max']);
        }

        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate((int) config('storefront.per_page', 12))->withQueryString();
    }

    /** بحث نصّي عبر الاسم (عربي/إنجليزي)، SKU، وكلمات البحث. */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.trim($term).'%';
        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('search_keywords', 'like', $like);
        });
    }

    /**
     * اقتراحات البحث الفوري: ما يطابق الحروف المكتوبة من أسماء المنتجات والأقسام
     * والعلامات، ليختار الزبون الاسم بدل تهجئته كاملًا.
     *
     * المطابقة نفسها المستعملة في صفحة النتائج (`applySearch`) عمدًا: اقتراحٌ
     * لا يقود إلى نتيجة أسوأ من غياب الاقتراح. الترتيب وحده يختلف — ما يبدأ
     * بالحروف المكتوبة يتقدّم على ما يحتويها في وسطه.
     *
     * حرفٌ واحد يطابق نصف الفهرس، فالحدّ الأدنى حرفان.
     *
     * @return array<int, array{type: string, label: string, url: string, image: string|null}>
     */
    public function suggest(string $term, int $limit = 6): array
    {
        // القصّ عند 80 حرفًا: المدخل عامّ، وسلسلة بطول ميغابايت تصير نمط LIKE
        // يمسح الجدول. لا اسم منتج يبلغ هذا الطول أصلًا.
        $term = mb_substr(trim($term), 0, 80);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';
        // بادئة أولًا: من كتب «قم» يريد «قميص» قبل «طقم قماش».
        $prefixFirst = 'case when name like ? then 0 else 1 end';
        $prefix = $term.'%';

        $products = Product::query()->active()->visible()
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('search_keywords', 'like', $like))
            ->with('primaryImage')
            ->orderByRaw($prefixFirst, [$prefix])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $categories = Category::query()->active()
            ->where('name', 'like', $like)
            ->orderByRaw($prefixFirst, [$prefix])
            ->orderBy('name')
            ->limit(3)
            ->get(['id', 'name', 'slug']);

        $brands = Brand::query()->active()
            ->where('name', 'like', $like)
            ->orderByRaw($prefixFirst, [$prefix])
            ->orderBy('name')
            ->limit(3)
            ->get(['id', 'name', 'slug']);

        return [
            ...$products->map(fn (Product $p) => [
                'type' => 'product',
                'label' => $p->name,
                'url' => route('storefront.product', $p->slug),
                'image' => $p->primaryImage?->url(),
            ])->all(),
            ...$categories->map(fn (Category $c) => [
                'type' => 'category',
                'label' => $c->name,
                'url' => route('storefront.category', $c->slug),
                'image' => null,
            ])->all(),
            ...$brands->map(fn (Brand $b) => [
                'type' => 'brand',
                'label' => $b->name,
                'url' => route('storefront.brand', $b->slug),
                'image' => null,
            ])->all(),
        ];
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('retail_price'),
            'price_desc' => $query->orderByDesc('retail_price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('id'), // الأحدث
        };
    }

    /** منتج معروض بالـslug (أو 404). */
    public function findProductBySlug(string $slug): Product
    {
        return Product::query()->active()->visible()
            ->where('slug', $slug)
            ->with(['images', 'variants.attributeValues', 'variants.inventoryStocks', 'defaultVariant.inventoryStocks', 'brand', 'category', 'attributes.values', 'unit'])
            ->firstOrFail();
    }

    /**
     * @return Collection<int, Category>
     *
     * بيانات مرجعية مستقرّة تُقرأ على كل صفحة متجر — مُخزّنة مؤقتًا وتُبطَل عند أي تعديل
     * فئة (Category::saved/deleted). يقلّل استعلامات التنقّل بشكل كبير في الإنتاج.
     */
    public function categories(): Collection
    {
        return Cache::remember('storefront:categories', now()->addMinutes(30),
            fn () => Category::query()->active()->orderBy('sort_order')->orderBy('name')->get());
    }

    public function findCategoryBySlug(string $slug): Category
    {
        return Category::query()->active()->where('slug', $slug)->firstOrFail();
    }

    /** @return Collection<int, Brand> — مُخزّنة مؤقتًا وتُبطَل عند تعديل علامة. */
    public function brands(): Collection
    {
        return Cache::remember('storefront:brands', now()->addMinutes(30),
            fn () => Brand::query()->active()->orderBy('name')->get());
    }

    public function findBrandBySlug(string $slug): Brand
    {
        return Brand::query()->active()->where('slug', $slug)->firstOrFail();
    }

    // ---- تسعير/توافر (يُعاد استخدامهما من CartService — لا تكرار منطق) ----

    public function sellingPrice(Product $product): float
    {
        $variant = $product->defaultVariant;

        return $variant ? $this->carts->sellingPrice($variant) : (float) $product->retail_price;
    }

    public function regularPrice(Product $product): float
    {
        $variant = $product->defaultVariant;

        return (float) ($variant?->retail_price ?? $product->retail_price);
    }

    /** على عرض ترويجي؟ (سعر البيع أقل من التجزئة). */
    public function onSale(Product $product): bool
    {
        return $this->sellingPrice($product) + 1e-9 < $this->regularPrice($product);
    }

    /**
     * التوافر عبر المستودعات (Σ on_hand − reserved). عند تمرير فرع، يُحصر بمستودعاته
     * (توافر مدرك للفرع — where applicable).
     *
     * يُجمَع عبر **كل متغيّرات المنتج المفعّلة** لا المتغيّر الافتراضي وحده: منتج
     * بمقاسات يحمل مخزونه على متغيّرات المقاسات، وكان المتغيّر الافتراضي بصفر
     * فيظهر «غير متوفّر» في القوائم رغم توفّر المقاسات فعلًا.
     */
    public function availableQty(Product $product, ?Branch $branch = null): float
    {
        $variantIds = $this->sellableVariantIds($product);
        if ($variantIds === []) {
            return 0.0;
        }

        $stocks = InventoryStock::query()
            ->whereIn('variant_id', $variantIds)
            ->when($branch !== null, fn (Builder $q) => $q->whereHas(
                'warehouse', fn (Builder $w) => $w->where('branch_id', $branch->id),
            ))
            ->get(['on_hand', 'reserved']);

        return (float) $stocks->sum(fn ($s) => (float) $s->on_hand - (float) $s->reserved);
    }

    /**
     * معرّفات المتغيّرات القابلة للبيع. تُقرأ من العلاقة المحمّلة إن وُجدت حتى لا
     * تتحوّل قوائم المنتجات إلى استعلام لكل بطاقة (N+1).
     *
     * @return array<int, int>
     */
    private function sellableVariantIds(Product $product): array
    {
        if ($product->relationLoaded('variants')) {
            $ids = $product->variants->filter(fn ($v) => $v->is_active)->pluck('id');
        } else {
            $ids = ProductVariant::where('product_id', $product->id)->where('is_active', true)->pluck('id');
        }

        // احتياط: منتج بلا متغيّرات مفعّلة يعود لمتغيّره الافتراضي (السلوك السابق).
        return $ids->isNotEmpty() ? $ids->all() : array_filter([$product->defaultVariant?->id]);
    }

    public function inStock(Product $product, ?Branch $branch = null): bool
    {
        return $this->availableQty($product, $branch) > 1e-9;
    }
}
