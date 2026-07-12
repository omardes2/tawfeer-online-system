<?php

namespace App\Http\Controllers\Admin\Recommendations;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Recommendations\Models\ProductRecommendation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * إدارة التوصيات اليدوية والاستثناءات (Phase 6 / ADR-045) — متحكّم رفيع.
 * تسمح للمشرف بتثبيت توصيات (include) أو حجب منتجات (exclude) لكل منتج ونوع.
 */
class RecommendationRuleController extends Controller
{
    public function index(Request $request): View
    {
        $rules = ProductRecommendation::query()
            ->with(['product:id,name', 'recommended:id,name'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.recommendations.index', [
            'rules' => $rules,
            'products' => Product::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'recommended_product_id' => ['required', 'integer', 'exists:products,id', 'different:product_id'],
            'type' => ['required', Rule::in(['related', 'cross_sell', 'upsell', 'complementary', 'fbt'])],
            'kind' => ['required', Rule::in(['include', 'exclude'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        ProductRecommendation::updateOrCreate(
            [
                'product_id' => $data['product_id'],
                'recommended_product_id' => $data['recommended_product_id'],
                'type' => $data['type'],
            ],
            [
                'kind' => $data['kind'],
                'position' => $data['position'] ?? 0,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('تم حفظ قاعدة التوصية.'));
    }

    public function destroy(ProductRecommendation $recommendation): RedirectResponse
    {
        $recommendation->delete();

        return back()->with('success', __('تم حذف القاعدة.'));
    }
}
