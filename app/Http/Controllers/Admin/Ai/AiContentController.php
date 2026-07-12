<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\GenerateContentRequest;
use App\Modules\Ai\Services\AiContentService;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * مساعد محتوى المنتجات بالذكاء الاصطناعي (Phase 6 / ADR-044) — متحكّم رفيع.
 *
 * لا يستدعي أي مزوّد مباشرةً؛ يفوّض إلى AiContentService (المزوّد محقون خلف عقد).
 * **يُرجِع اقتراحًا فقط في JSON** — لا يكتب أي حقل منتج ولا ينشر. الموظّف يراجع/يطبّق يدويًا.
 */
class AiContentController extends Controller
{
    public function __construct(private readonly AiContentService $service) {}

    public function generate(GenerateContentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $product = isset($data['product_id']) ? Product::find($data['product_id']) : null;

        // صورة المنتج تُرسَل فقط عند تفعيل التحليل البصري + طلب صريح (المتطلّب الأمني).
        $imageUrl = null;
        if (($data['use_image'] ?? false) && config('ai.vision') && $product) {
            $imageUrl = $product->loadMissing('primaryImage')->primaryImage?->url();
        }

        // نقطة نهاية JSON (خارج api/*): نرجع أخطاء الخدمة (حدّ معدّل/نوع) JSON صراحةً.
        try {
            $result = $this->service->generate(
                type: $data['type'],
                action: $data['action'] ?? 'generate',
                locale: $data['locale'] ?? 'ar',
                inputs: $data['inputs'] ?? [],
                tone: $data['tone'] ?? null,
                product: $product,
                imageUrl: $imageUrl,
                actor: $request->user(),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'suggestion' => $result->content,
            'status' => $result->status,
            'model' => $result->model,
            'total_tokens' => $result->totalTokens(),
            // تذكير صريح للواجهة: المحتوى اقتراح لا يُنشر تلقائيًا.
            'applied' => false,
        ]);
    }
}
