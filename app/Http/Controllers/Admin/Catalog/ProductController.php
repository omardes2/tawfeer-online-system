<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkDeleteProductsRequest;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductImageService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductImageService $imageService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $sort = (string) $request->query('sort');

        $query = Product::query()
            ->with(['category', 'primaryImage', 'defaultVariant'])
            ->withSum('stocks', 'on_hand')       // stocks_sum_on_hand
            ->withSum('stocks', 'reserved')      // stocks_sum_reserved
            ->withSum('orderItems', 'qty_shipped') // order_items_sum_qty_shipped (المباع)
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')))
            // الظهور على الموقع بدل حالة التحرير: هذا ما يسأل عنه المشغّل فعلًا
            // («ما الذي يراه الزبون؟»)، والتبديل متاح في العمود نفسه من الجدول.
            ->when($request->filled('visibility'), fn ($q) => $q->where('visibility', $request->string('visibility')));

        match ($sort) {
            'price_asc' => $query->orderBy('retail_price'),
            'price_desc' => $query->orderByDesc('retail_price'),
            'qty_asc' => $query->orderByRaw('COALESCE(stocks_sum_on_hand, 0) asc'),
            'qty_desc' => $query->orderByRaw('COALESCE(stocks_sum_on_hand, 0) desc'),
            default => $query->orderBy('sort_order')->orderBy('name'),
        };

        return view('admin.catalog.products.index', [
            'products' => $query->paginate(20)->withQueryString(),
            'activeSort' => $sort,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.catalog.products.form', $this->formData(new Product));
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $product = $this->service->create($this->withRelationSets($request));

        return redirect()->route('admin.products.edit', $product)->with('success', __('تم إنشاء المنتج. يمكنك الآن رفع الصور.'));
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        return view('admin.catalog.products.form', $this->formData(
            $product->load(['tags', 'attributes.values', 'images', 'variants.attributeValues']),
        ));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->service->update($product, $this->withRelationSets($request));

        return redirect()->route('admin.products.index')->with('success', __('تم تحديث المنتج.'));
    }

    /**
     * النموذج يمثّل المجموعة الكاملة للوسوم/السمات؛ نمرّرها دائمًا (حتى فارغة)
     * ليتمكّن المستخدم من إزالة الكل — بخلاف دلالة الـAPI الجزئية.
     */
    private function withRelationSets(Request $request): array
    {
        return array_merge($request->validated(), [
            'tag_ids' => $request->input('tag_ids', []),
            'attribute_ids' => $request->input('attribute_ids', []),
        ]);
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $this->service->delete($product);

        return redirect()->route('admin.products.index')->with('success', __('تم حذف المنتج.'));
    }

    /**
     * حذف الأصناف المُحدَّدة.
     *
     * الصلاحية تُفحص لكل صنف على حدة — قائمة المعرّفات تصل من المتصفّح، وفحصٌ
     * عامّ واحد يسمح بتمرير صنف لا يملك المستخدم حذفه ضمن الدفعة.
     *
     * داخل معاملة: «حذف المحدَّد» وعدٌ بالكل أو لا شيء؛ سقوطُه في المنتصف يترك
     * المشغّل أمام قائمة لا يعرف ما جرى لها.
     */
    public function bulkDestroy(BulkDeleteProductsRequest $request): RedirectResponse
    {
        $products = Product::whereIn('id', $request->validated('products'))->get();

        $denied = $products->filter(fn (Product $p) => $request->user()->cannot('delete', $p));
        if ($denied->isNotEmpty()) {
            return back()->with('error', __('لا تملك صلاحية حذف :count من الأصناف المحدَّدة.', ['count' => $denied->count()]));
        }

        DB::transaction(function () use ($products) {
            $products->each(fn (Product $p) => $this->service->delete($p));
        });

        return back()->with('success', trans_choice(
            '{1}تم حذف صنف واحد.|{2}تم حذف صنفين.|[3,*]تم حذف :count أصناف.',
            $products->count(),
            ['count' => $products->count()],
        ));
    }

    /** تبديل إظهار المنتج على الموقع (visible ⇄ hidden). */
    public function toggleVisibility(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $show = $product->visibility !== 'visible';
        $product->update(['visibility' => $show ? 'visible' : 'hidden']);

        return back()->with('success', $show ? __('أصبح المنتج ظاهرًا على الموقع.') : __('أُخفي المنتج من الموقع.'));
    }

    public function storeImage(StoreProductImageRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        // ألبوم الصور: عدة ملفات دفعة واحدة (لا تُعيَّن أساسية).
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $this->imageService->store($product, $file, ['is_primary' => false]);
            }

            return back()->with('success', __('تمت إضافة الصور إلى الألبوم.'));
        }

        // صورة مفردة (المصغّرة).
        $this->imageService->store($product, $request->file('image'), $request->safe()->except(['image', 'images']));

        return back()->with('success', __('تم رفع الصورة.'));
    }

    public function setPrimaryImage(Product $product, ProductImage $image): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);
        $this->imageService->setPrimary($product, $image);

        return back()->with('success', __('تم تعيين الصورة الأساسية.'));
    }

    public function destroyImage(Product $product, ProductImage $image): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);
        $this->imageService->delete($image);

        return back()->with('success', __('تم حذف الصورة.'));
    }

    private function formData(Product $product): array
    {
        $data = [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'tags' => ProductTag::orderBy('name')->get(),
            'attributes' => ProductAttribute::orderBy('name')->get(),
            'variantMatrix' => ['attributes' => [], 'existing' => [], 'defaultPrice' => 0],
        ];

        // إعداد مصفوفة المتغيّرات الحيّة: السمات وقيمها + التركيبات الموجودة (سعر/كمية).
        if ($product->exists) {
            $product->loadMissing('variants.attributeValues');

            $optionVariants = $product->variants
                ->filter(fn ($v) => $v->attributeValues->isNotEmpty())->values();

            $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
            $stock = $warehouse
                ? InventoryStock::where('warehouse_id', $warehouse->id)
                    ->whereIn('variant_id', $optionVariants->pluck('id'))
                    ->pluck('on_hand', 'variant_id')->all()
                : [];

            // العدد الأصلي = مجموع رصيد كل المتغيّرات (الافتراضي + المقاسات). يطابق
            // ما تتحقّق منه ProductVariantController::originalQuantity، فلا يعرض
            // النموذج رقمًا ويرفض الحفظُ بآخر.
            $defaultVariant = $product->variants->first(fn ($v) => $v->attributeValues->isEmpty());
            $defaultQty = $warehouse && $defaultVariant
                ? (float) InventoryStock::where('warehouse_id', $warehouse->id)
                    ->where('variant_id', $defaultVariant->id)->value('on_hand')
                : 0.0;
            $originalQty = $defaultQty + array_sum($stock);

            $attributes = ProductAttribute::where('is_active', true)
                ->with(['values' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
                ->orderBy('name')->get()
                ->filter(fn ($a) => $a->values->isNotEmpty())
                ->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'name' => $a->name,
                    'values' => $a->values->map(fn ($v) => [
                        'id' => (int) $v->id,
                        'label' => $v->label ?: $v->value,
                        'color' => $v->color_hex,
                    ])->values(),
                ])->values();

            $data['variantMatrix'] = [
                'attributes' => $attributes,
                'existing' => $optionVariants->map(fn ($v) => [
                    'values' => $v->attributeValues->pluck('id')->map(fn ($i) => (int) $i)->values(),
                    'price' => (float) $v->retail_price,
                    'stock' => (float) ($stock[$v->id] ?? 0),
                ])->values(),
                'defaultPrice' => (float) ($product->defaultVariant?->retail_price ?? $product->retail_price ?? 0),
                // العدد الأصلي: كمية المتغيّر الافتراضي إن لم تُوزَّع بعد، وإلا مجموع المقاسات.
                // المصفوفة توزّع هذا العدد ولا تضيف إليه.
                'originalQty' => (float) $originalQty,
            ];
        }

        return $data;
    }
}
