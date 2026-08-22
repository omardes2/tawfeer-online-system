<?php

namespace App\Http\Controllers\Admin\AiAgent;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiAgent\ProductKnowledgeRequest;
use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المعرفة البيعية للأصناف — ما يقوله الوكيل، لا ما يقوله الكتالوج.
 *
 * الكتالوج يصف الصنف؛ وهذه الشاشة تصف **كيف يُباع**: لماذا يشتريه الزبون، وما
 * الاعتراض المتوقّع وجوابه، وما يُسأل عنه كثيرًا.
 *
 * و`is_ready` **إذنٌ بالبيع لا علامةُ اكتمال**: الصنف غير الجاهز لا يظهر في
 * بحث الوكيل أصلًا، ويُحوَّل السؤال عنه إلى موظفة. فالشاشة كلّها موجودة لتُنتج
 * هذه العلامة عن وعي — لا ليمتلئ حقلٌ في قاعدة البيانات.
 */
class ProductKnowledgeController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('ai_agent.knowledge.view');

        $ready = $request->string('ready')->value();

        $products = Product::query()
            ->with(['category:id,name', 'primaryImage', 'knowledge'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')))
            // الفلترة على وجود المعرفة لا على العمود وحده: صنفٌ بلا صفٍّ أصلًا
            // «غير جاهز» تمامًا كصنفٍ صفُّه موجود وعلامته مطفأة.
            ->when($ready === 'yes', fn ($q) => $q->whereHas('knowledge', fn ($k) => $k->where('is_ready', true)))
            ->when($ready === 'no', fn ($q) => $q->where(fn ($w) => $w
                ->whereDoesntHave('knowledge')
                ->orWhereHas('knowledge', fn ($k) => $k->where('is_ready', false))))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.ai_agent.knowledge.index', [
            'products' => $products,
            'ready' => $ready,
            'search' => $request->string('search')->value(),
            // عدّادٌ يقول للمالك أين هو من التجهيز: «١٢ من ٨٠» أوضح من قائمةٍ
            // يتصفّحها ليعدّ بنفسه.
            'readyCount' => ProductKnowledge::where('is_ready', true)->count(),
            'totalCount' => Product::count(),
        ]);
    }

    public function edit(Product $product): View
    {
        $this->authorize('ai_agent.knowledge.view');

        return view('admin.ai_agent.knowledge.form', [
            'product' => $product->load('category:id,name', 'primaryImage'),
            'knowledge' => $product->knowledge ?? new ProductKnowledge,
        ]);
    }

    public function update(ProductKnowledgeRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        ProductKnowledge::updateOrCreate(
            ['product_id' => $product->id],
            [
                // الفراغات تُنظَّف قبل الحفظ: سطرٌ فارغ في نقاط البيع يصل إلى
                // النموذج فراغًا، فيقرؤه على أنه نقطةٌ لا يعرف ما فيها.
                'selling_points' => $this->clean($data['selling_points'] ?? []),
                'use_cases' => $this->clean($data['use_cases'] ?? []),
                'objections' => $this->pairs($data['objections'] ?? [], 'objection', 'response'),
                'faq' => $this->pairs($data['faq'] ?? [], 'question', 'answer'),
                'tone_notes' => $data['tone_notes'] ?? null,
                'is_ready' => $request->boolean('is_ready'),
                'updated_by' => $request->user()?->id,
            ],
        );

        return redirect()
            ->route('admin.ai_agent.knowledge.edit', $product)
            ->with('success', $request->boolean('is_ready')
                ? __('حُفظت المعرفة — الوكيل يبيع هذا الصنف الآن.')
                : __('حُفظت المعرفة — الصنف غير جاهز بعد، والوكيل يحوّل السؤال عنه إلى موظفة.'));
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function clean(array $values): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $values,
        ), fn (string $v) => $v !== ''));
    }

    /**
     * أزواجٌ يُحفظ منها الكامل وحده.
     *
     * اعتراضٌ بلا جواب أسوأ من لا شيء: يقرؤه النموذج فيعرف الاعتراض ولا يملك
     * ردًّا عليه.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, string>>
     */
    private function pairs(array $rows, string $first, string $second): array
    {
        $out = [];

        foreach ($rows as $row) {
            $a = trim((string) ($row[$first] ?? ''));
            $b = trim((string) ($row[$second] ?? ''));

            if ($a !== '' && $b !== '') {
                $out[] = [$first => $a, $second => $b];
            }
        }

        return $out;
    }
}
