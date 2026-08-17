<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreSocialPostRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\SocialPost;
use App\Modules\Marketing\Services\SocialPostComposer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء محتوى منشورات فيسبوك وإنستغرام.
 *
 * **يُولّد ويحفظ ولا ينشر.** النشر التلقائي يحتاج صلاحيات صفحاتٍ ومراجعة تطبيق
 * من المنصّة، والأهمّ أنه يجعل خطأً في التوليد يظهر أمام الزبائن قبل أن يراه
 * أحد. فالمخرَج نصٌّ يُنسخ ورابطٌ متتبَّع، و«نُشر» علامةٌ يضعها إنسان.
 */
class SocialPostController extends Controller
{
    public function __construct(private readonly SocialPostComposer $composer) {}

    public function index(Request $request): View
    {
        $posts = SocialPost::with(['product', 'channel', 'author'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('product'), fn ($q, $p) => $q->where('product_id', (int) $p))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.marketing.social_posts', [
            'posts' => $posts,
            'channels' => AdChannel::ordered()->get(),
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku', 'slug']),
            'status' => (string) $request->query('status', ''),
            // المزوّد المعطّل يُقال صراحةً: الزرّ الذي لا يعمل بلا تفسير يُقرأ عطلًا.
            'aiReady' => config('ai.default') !== 'null',
        ]);
    }

    /** اقتراح نصّ — JSON، ولا يحفظ شيئًا. */
    public function suggest(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('marketing.social.manage'), 403);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'platform' => ['required', 'string', 'in:facebook,instagram,both'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'tone' => ['nullable', 'string', 'max:30'],
        ]);

        $product = Product::with(['category', 'brand', 'defaultVariant'])->findOrFail($data['product_id']);

        try {
            $result = $this->composer->suggest(
                product: $product,
                platform: $data['platform'],
                locale: $data['locale'] ?? 'ar',
                tone: $data['tone'] ?? null,
                actor: $request->user(),
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'suggestion' => $result->content,
            'status' => $result->status,
            'model' => $result->model,
            // تذكير صريح للواجهة: اقتراح لا يُنشر ولا يُحفَظ من تلقائه.
            'saved' => false,
        ]);
    }

    /** الرابط المتتبَّع لصنفٍ على صفحة — يُعرَض للنسخ قبل الحفظ. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'ad_channel_id' => ['nullable', 'integer', 'exists:ad_channels,id'],
            'platform' => ['required', 'string', 'in:facebook,instagram,both'],
        ]);

        return response()->json([
            'link' => $this->composer->trackedLink(
                Product::findOrFail($data['product_id']),
                isset($data['ad_channel_id']) ? AdChannel::find($data['ad_channel_id']) : null,
                $data['platform'],
            ),
        ]);
    }

    public function store(StoreSocialPostRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // الرابط يُبنى عند الحفظ ويُخزَّن: تغيّرُ الإعدادات لاحقًا كان سيُظهر
        // رابطًا غير الذي وصل الزبائن فعلًا.
        $product = isset($data['product_id']) ? Product::find($data['product_id']) : null;
        $channel = isset($data['ad_channel_id']) ? AdChannel::find($data['ad_channel_id']) : null;

        SocialPost::create($data + [
            'link' => $product ? $this->composer->trackedLink($product, $channel, $data['platform']) : null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', __('حُفظ المنشور.'));
    }

    public function update(StoreSocialPostRequest $request, SocialPost $socialPost): RedirectResponse
    {
        $socialPost->update($request->validated());

        return back()->with('success', __('حُدِّث المنشور.'));
    }

    /** وسمُ «نُشر» — علامةٌ يضعها إنسان، لا نشرٌ من النظام. */
    public function markPublished(Request $request, SocialPost $socialPost): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.social.manage'), 403);

        $socialPost->update(['status' => 'published', 'published_at' => now()]);

        return back()->with('success', __('وُسم المنشور بأنه نُشر.'));
    }

    public function destroy(Request $request, SocialPost $socialPost): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.social.manage'), 403);

        $socialPost->delete();

        return back()->with('success', __('حُذف المنشور.'));
    }
}
