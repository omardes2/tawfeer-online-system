<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Requests\Inventory\UpdateProductQuantitiesRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
        private readonly ProductService $products,
    ) {}

    /** صفحة «المخزن»: عرض الأصناف بالأسعار والكمية المتوفرة (متمركزة حول المنتج). */
    public function stocks(Request $request): View
    {
        $this->authorize('inventory.stocks.view');

        // تفصيل المتغيّرات مع كمياتها: يجعل مجموع الصنف قابلًا للمطابقة بصريًا
        // (إجمالي الصنف = مجموع كميات متغيّراته) ويكشف أي متغيّر خارج مصفوفة المنتج.
        $products = Product::query()->with([
            'category:id,name',
            'variants' => fn ($q) => $q->select('id', 'product_id', 'sku', 'name')
                ->with('attributeValues:id,value,label')
                ->withSum('inventoryStocks as on_hand_sum', 'on_hand')
                ->withSum('inventoryStocks as reserved_sum', 'reserved'),
        ])
            ->withSum('stocks as on_hand_sum', 'on_hand')
            ->withSum('stocks as reserved_sum', 'reserved')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.inventory.stocks', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'search' => $request->input('search'),
            'activeCategory' => $request->integer('category') ?: null,
        ]);
    }

    /**
     * كرت الصنف: بياناته + سجل المخزن — **الحركات الفعلية على الكمية فقط**.
     *
     * الحجز وتحرير الحجز إجراءان داخليان على دلو `reserved` لا يغيّران الرصيد الفعلي
     * (كل بيع يولّد ثلاثتها: حجز ثم تحرير ثم صرف)، فعرضهما يُربك القراءة ويوحي بحركات
     * لم تقع. تُستبعَد بالفلترة على `bucket = on_hand` لا بقائمة أنواع، فتظهر تلقائيًا
     * أي أنواع جديدة تمسّ الرصيد فعلًا.
     */
    public function showProduct(Product $product): View
    {
        $this->authorize('inventory.stocks.view');

        $variantIds = $product->variants()->pluck('id');

        $ledger = InventoryLedger::query()
            ->whereIn('variant_id', $variantIds)
            ->where('bucket', 'on_hand')
            ->with(['warehouse:id,name', 'movement:id,reason,reference_type,reference_id'])
            ->latest('id')->paginate(20);

        $onHand = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('on_hand');
        $reserved = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('reserved');

        return view('admin.inventory.product-ledger', [
            'product' => $product->load('category:id,name'),
            // تفصيل الكميات لكل متغيّر — لمطابقة إجمالي الصنف مع مقاساته.
            'variants' => $product->variants()->with('attributeValues:id,value,label')
                ->withSum('inventoryStocks as on_hand_sum', 'on_hand')
                ->withSum('inventoryStocks as reserved_sum', 'reserved')
                ->orderBy('id')->get(),
            'ledger' => $ledger,
            'available' => $onHand - $reserved,
            'onHand' => $onHand,
        ]);
    }

    /** صفحة تعديل سريع لصنف من المخزن: الفئة، كود المنتج، سعر الشراء/البيع/الجملة. */
    public function editProduct(Product $product): View
    {
        $this->authorize('update', $product);

        $warehouses = Warehouse::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']);
        $warehouse = $warehouses->firstWhere('id', (int) request('warehouse_id')) ?? $warehouses->first();

        return view('admin.inventory.product-edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'warehouses' => $warehouses,
            'currentWarehouse' => $warehouse,
            // المخزون يُحفظ لكل متغيّر على حدة، فالتعديل صفٌّ لكل متغيّر لا خانة واحدة.
            'variantRows' => $this->variantRows($product, $warehouse),
        ]);
    }

    /**
     * صفوف تعديل الكمية: متغيّرات الصنف المفعّلة وكمية كلٍّ منها في المستودع.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function variantRows(Product $product, ?Warehouse $warehouse)
    {
        if ($warehouse === null) {
            return collect();
        }

        $variants = $product->variants()->where('is_active', true)
            ->with('attributeValues:id,value,label')->orderBy('id')->get();

        $onHand = InventoryStock::where('warehouse_id', $warehouse->id)
            ->whereIn('variant_id', $variants->pluck('id'))
            ->pluck('on_hand', 'variant_id');

        return $variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'sku' => $v->sku,
            // اسم الخيارات (لون/مقاس) إن وُجدت، وإلا الصنف نفسه بلا خيارات.
            'options' => $v->attributeValues->map(fn ($a) => $a->label ?: $a->value)->filter()->implode(' - '),
            'on_hand' => (float) ($onHand[$v->id] ?? 0),
        ]);
    }

    /**
     * تعديل كميات الصنف يدويًّا من كرت الصنف.
     *
     * لا كتابة مباشرة على الرصيد: يُحسب الفرق ويُسجَّل **حركة تسوية**
     * (`adjustment_in`/`adjustment_out`) بسبب — فيبقى أثرٌ يُسأل عنه لاحقًا،
     * ويبقى الرصيد نتيجةَ حركات لا رقمًا مكتوبًا.
     *
     * وبلا تكلفة عمدًا: التسوية بلا تكلفة لا تعيد حساب متوسط التكلفة
     * (`InventoryService::record`)، فلا ينحرف أساس التكلفة بتصحيحِ عدد.
     */
    public function updateQuantities(UpdateProductQuantitiesRequest $request, Product $product): RedirectResponse
    {
        $warehouse = Warehouse::findOrFail($request->validated('warehouse_id'));
        $reason = $request->validated('reason') ?: __('تعديل يدوي من كرت الصنف');

        $allowed = $product->variants()->where('is_active', true)->pluck('id');
        $changed = 0;

        try {
            DB::transaction(function () use ($request, $warehouse, $reason, $allowed, &$changed) {
                foreach ($request->validated('quantities') as $variantId => $target) {
                    // الخانة الفارغة = «لا تغيير»، لا صفر.
                    if ($target === null || $target === '') {
                        continue;
                    }
                    if (! $allowed->contains((int) $variantId)) {
                        continue;   // متغيّر لا يخصّ هذا الصنف — يُتجاهل بصمت.
                    }

                    $variant = ProductVariant::findOrFail((int) $variantId);
                    $current = (float) (InventoryStock::where('variant_id', $variant->id)
                        ->where('warehouse_id', $warehouse->id)->value('on_hand') ?? 0);

                    $diff = round((float) $target - $current, 3);
                    if (abs($diff) < 1e-9) {
                        continue;
                    }

                    $opts = ['reason' => $reason, 'note' => __('من كرت الصنف')];
                    $diff > 0
                        ? $this->inventory->adjustIn($variant, $warehouse, $diff, null, $opts)
                        : $this->inventory->adjustOut($variant, $warehouse, abs($diff), $opts);

                    $changed++;
                }
            });
        } catch (ValidationException $e) {
            // أشهرها: خفضٌ يجعل المتاح سالبًا (كمية محجوزة لطلبات قائمة).
            return back()->withErrors($e->errors())->withInput();
        }

        if ($changed === 0) {
            return back()->with('warning', __('لم تتغيّر أي كمية.'));
        }

        return back()->with('success', trans_choice(
            '{1}عُدِّلت كمية متغيّر واحد.|{2}عُدِّلت كميتا متغيّرين.|[3,*]عُدِّلت كميات :count متغيّرات.',
            $changed, ['count' => $changed],
        ));
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku,'.$product->id],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->products->update($product, $data);

        // مزامنة أسعار المتغيّر الافتراضي — لأن صفحات الطلب/المنتجات تقرأ سعر المتغيّر.
        if ($variant = $product->defaultVariant()->first()) {
            $variant->update([
                'cost_price' => $data['cost_price'] ?? $variant->cost_price,
                'retail_price' => $data['retail_price'] ?? $variant->retail_price,
                'wholesale_price' => $data['wholesale_price'] ?? $variant->wholesale_price,
            ]);
        }

        return redirect()->route('admin.inventory.stocks')->with('success', __('حُدّث الصنف.'));
    }

    public function movements(Request $request): View
    {
        $this->authorize('inventory.movements.view');

        $movements = InventoryMovement::query()->with(['variant', 'warehouse'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.inventory.movements', compact('movements'));
    }

    public function reservations(Request $request): View
    {
        $this->authorize('viewAny', StockReservation::class);

        $reservations = StockReservation::query()->with(['variant', 'warehouse'])
            ->latest('id')->paginate(20);

        return view('admin.inventory.reservations', compact('reservations'));
    }

    public function releaseReservation(StockReservation $reservation): RedirectResponse
    {
        $this->authorize('release', $reservation);

        try {
            $this->reservations->release($reservation);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم تحرير الحجز.'));
    }

    public function operations(): View
    {
        $this->authorize('inventory.stocks.view');

        return view('admin.inventory.operations', [
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function receive(ReceiveStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.receive');
        [$variant, $warehouse] = $this->resolve($request);
        $this->inventory->receive($variant, $warehouse, (float) $request->validated('qty'), (float) $request->validated('unit_cost'), $request->only(['reason', 'note']));

        return back()->with('success', __('تم استلام المخزون.'));
    }

    public function issue(IssueStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.issue');
        [$variant, $warehouse] = $this->resolve($request);

        try {
            $this->inventory->issue($variant, $warehouse, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم صرف المخزون.'));
    }

    public function transfer(TransferStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.transfer');
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $from = Warehouse::where('uuid', $request->validated('from_warehouse'))->firstOrFail();
        $to = Warehouse::where('uuid', $request->validated('to_warehouse'))->firstOrFail();

        try {
            $this->inventory->transfer($variant, $from, $to, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم التحويل بين المستودعين.'));
    }

    private function resolve($request): array
    {
        return [
            ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail(),
            Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail(),
        ];
    }
}
