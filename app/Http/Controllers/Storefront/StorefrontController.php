<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Store\Services\StorefrontService;
use App\Support\Contracts\StorefrontRecommendationProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * واجهة المتجر العامّة (ADR-034): صفحات مُخدَّمة من الخادم (SSR) للـSEO، تقرأ عبر
 * `StorefrontService` (لا منطق أعمال في المتحكّم/القوالب). السلة/الإتمام عبر واجهات
 * 3.1/3.2 من العميل. أقسام التسويق عبر عقد التوصيات (Null افتراضيًا — ADR-032).
 */
class StorefrontController extends Controller
{
    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StorefrontRecommendationProvider $reco,
    ) {}

    public function home(): View
    {
        return view('storefront.home', [
            'featured' => $this->reco->featured(8),
            'newArrivals' => $this->reco->newArrivals(8),
            'categories' => $this->storefront->categories()->take(8),
            'brands' => $this->storefront->brands()->take(12),
        ]);
    }

    public function index(Request $request): View
    {
        return $this->listing($request, __('storefront.all_products'));
    }

    public function category(Request $request, string $slug): View
    {
        $category = $this->storefront->findCategoryBySlug($slug);
        $request->merge(['category' => $category->slug]);

        return $this->listing($request, $category->name, ['category' => $category]);
    }

    public function brand(Request $request, string $slug): View
    {
        $brand = $this->storefront->findBrandBySlug($slug);
        $request->merge(['brand' => $brand->slug]);

        return $this->listing($request, $brand->name, ['brand' => $brand]);
    }

    public function search(Request $request): View
    {
        return $this->listing($request, __('storefront.search_results'), ['isSearch' => true]);
    }

    public function categories(): View
    {
        return view('storefront.categories', ['categories' => $this->storefront->categories()]);
    }

    public function brands(): View
    {
        return view('storefront.brands', ['brands' => $this->storefront->brands()]);
    }

    public function show(string $slug): View
    {
        $product = $this->storefront->findProductBySlug($slug);

        return view('storefront.product', [
            'product' => $product,
            'related' => $this->reco->related($product, 8),
            'frequentlyBoughtTogether' => $this->reco->frequentlyBoughtTogether($product, 8),
            'crossSell' => $this->reco->crossSell($product, 8),
            'upsell' => $this->reco->upsell($product, 8),
            'bundles' => $this->reco->bundles($product, 8),
        ]);
    }

    public function cart(): View
    {
        return view('storefront.cart');
    }

    public function checkout(): View
    {
        return view('storefront.checkout');
    }

    public function setLocale(Request $request, string $locale): RedirectResponse
    {
        if (in_array($locale, (array) config('storefront.locales', ['ar', 'en']), true)) {
            $request->session()->put('storefront_locale', $locale);
        }

        return back();
    }

    /** @param array<string, mixed> $extra */
    private function listing(Request $request, string $heading, array $extra = []): View
    {
        $filters = $request->only(['category', 'brand', 'q', 'min', 'max', 'sort']);

        return view('storefront.products.index', array_merge([
            'products' => $this->storefront->list($filters),
            'heading' => $heading,
            'filters' => $filters,
            'categories' => $this->storefront->categories(),
            'brands' => $this->storefront->brands(),
        ], $extra));
    }
}
