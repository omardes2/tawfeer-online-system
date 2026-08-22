<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\PriceListRequest;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceListService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * قوائم أسعار التجّار.
 *
 * من تُسنَد له قائمةٌ يشتري بأسعارها، ويكون ربحه فرقَ ما بين سعر بيعه وسعرها —
 * كما هو ربح المسوّق بين سعر البيع وسعر الجملة. ومن لا قائمة له لا يتغيّر عليه
 * شيء.
 */
class PriceListsController extends Controller
{
    public function __construct(private readonly PriceListService $service) {}

    public function index(): View
    {
        $this->authorize('catalog.price_lists.view');

        return view('admin.catalog.price_lists.index', [
            'lists' => PriceList::with('parent')
                ->withCount(['items', 'users'])
                ->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('catalog.price_lists.manage');

        return view('admin.catalog.price_lists.form', $this->formData(new PriceList(['is_active' => true])));
    }

    public function store(PriceListRequest $request): RedirectResponse
    {
        $list = new PriceList;
        $this->service->assertNoCycle($list, $request->validated('parent_id'));

        $list = PriceList::create($request->validated() + ['created_by' => $request->user()->id]);

        return redirect()->route('admin.price_lists.edit', $list)
            ->with('success', __('أُنشئت القائمة. أضِف إليها الأصناف وأسعارها.'));
    }

    public function edit(Request $request, PriceList $priceList): View
    {
        $this->authorize('catalog.price_lists.manage');

        $items = $priceList->items()
            ->with(['variant.product:id,name,wholesale_price', 'variant.attributeValues'])
            ->get()
            ->sortBy(fn (PriceListItem $i) => $i->variant?->product?->name ?? '')
            ->values();

        // الموروث من الأب لبيان ما لم يُخصَّص هنا — فيعرف المدير ما يدفعه التاجر
        // فعلًا لا ما كُتب في هذه القائمة وحدها.
        $inherited = $priceList->parent_id
            ? $this->service->pricesForList($priceList, $this->variantIdsOfChain($priceList))
                ->except($items->pluck('variant_id')->all())
            : collect();

        return view('admin.catalog.price_lists.form', $this->formData($priceList) + [
            'items' => $items,
            'inherited' => $inherited,
            'inheritedVariants' => $inherited->isEmpty() ? collect() : ProductVariant::with('product:id,name,wholesale_price', 'attributeValues')
                ->whereIn('id', $inherited->keys())->get()->keyBy('id'),
        ]);
    }

    public function update(PriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        $this->service->assertNoCycle($priceList, $request->validated('parent_id'));

        $priceList->update($request->validated());

        return back()->with('success', __('حُدّثت القائمة.'));
    }

    /**
     * حذف القائمة وفكّ ارتباط أصحابها صراحةً.
     *
     * `nullOnDelete` على المفتاح لا يعمل هنا: الحذف **ناعم**، فيبقى المستخدم
     * مشيرًا إلى قائمةٍ محذوفة. الفكّ الصريح يمنع أمرين: سجلَّ مستخدمٍ يشير إلى
     * ما لا وجود له، واستعادةَ القائمة لاحقًا فتُعيد تسعير أشخاصٍ بلا أن يقصد
     * أحد. ويُنبَّه المدير قبلها لأن العودة إلى سعر الجملة ترفع أسعار شرائهم.
     */
    public function destroy(PriceList $priceList): RedirectResponse
    {
        $this->authorize('catalog.price_lists.manage');

        $users = DB::transaction(function () use ($priceList) {
            $count = $priceList->users()->count();
            $priceList->users()->update(['price_list_id' => null]);
            $priceList->delete();

            return $count;
        });

        return redirect()->route('admin.price_lists.index')->with(
            'success',
            $users > 0
                ? __('حُذفت القائمة، وعاد :n مستخدمًا إلى سعر الجملة.', ['n' => $users])
                : __('حُذفت القائمة.'),
        );
    }

    /** إضافة صنفٍ إلى القائمة أو تعديل سعره. */
    public function storeItem(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorize('catalog.price_lists.manage');

        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ], [], ['variant_id' => __('الصنف'), 'price' => __('السعر')]);

        PriceListItem::updateOrCreate(
            ['price_list_id' => $priceList->id, 'variant_id' => $data['variant_id']],
            ['price' => round((float) $data['price'], 2)],
        );

        return back()->with('success', __('حُفظ سعر الصنف.'));
    }

    public function destroyItem(PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $this->authorize('catalog.price_lists.manage');

        abort_unless($item->price_list_id === $priceList->id, 404);

        $item->delete();

        return back()->with('success', __('حُذف الصنف من القائمة — ويعود إلى سعر الأب أو الجملة.'));
    }

    /** @return array<string, mixed> */
    private function formData(PriceList $list): array
    {
        return [
            'list' => $list,
            // قائمةٌ لا تكون أبًا لنفسها، ولا لمن يرث منها (تُفحص الحلقة عند الحفظ أيضًا).
            'parents' => PriceList::when($list->exists, fn ($q) => $q->whereKeyNot($list->id))
                ->orderBy('name')->get(),
            'variants' => $this->sellableVariants(),
        ];
    }

    /**
     * المتغيّرات القابلة للبيع للاختيار — الاسم مع الخيارات كي يُميَّز المقاس.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sellableVariants()
    {
        return Product::query()->active()
            ->with(['variants.attributeValues', 'defaultVariant'])
            ->orderBy('name')
            ->get()
            ->flatMap(function (Product $p) {
                $withOptions = $p->variants->filter(fn ($v) => $v->attributeValues->isNotEmpty())->values();
                $sellable = $withOptions->isNotEmpty() ? $withOptions : collect([$p->defaultVariant])->filter();

                return $sellable->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'label' => $withOptions->isNotEmpty() ? $p->name.' — '.$v->optionLabel() : $p->name,
                    'sku' => $v->sku ?: $p->sku,
                    'wholesale' => $v->setRelation('product', $p)->effectiveWholesalePrice(),
                    'retail' => (float) ($v->retail_price ?: $p->retail_price),
                ]);
            })->values();
    }

    /**
     * معرّفات المتغيّرات المسعَّرة في سلسلة القائمة — أساس عرض «الموروث».
     *
     * @return array<int, int>
     */
    private function variantIdsOfChain(PriceList $list): array
    {
        return PriceListItem::whereIn('price_list_id', $list->ancestryIds())
            ->distinct()->pluck('variant_id')->all();
    }
}
