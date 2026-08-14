<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * مراجعة تقييمات الزبائن: اعتماد أو رفض.
 *
 * لا إنشاء ولا تحرير للنصّ — الرأي رأي صاحبه؛ ما يملكه المراجع هو نشره أو
 * منعه فقط. كل قرار يُوقَّع باسم المراجع ووقته (`moderated_by`/`moderated_at`)
 * فيبقى أثرٌ لمن نشر ماذا.
 */
class ProductReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProductReview::class);

        $status = $request->string('status')->toString();

        $reviews = ProductReview::query()
            ->with(['product:id,name,slug', 'customer:id,name', 'moderator:id,name'])
            // الافتراضي هو المعلّق: هو ما يحتاج قرارًا.
            ->when(in_array($status, [ProductReview::PENDING, ProductReview::APPROVED, ProductReview::REJECTED], true),
                fn ($q) => $q->where('status', $status),
                fn ($q) => $q->where('status', ProductReview::PENDING))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.catalog.reviews.index', [
            'reviews' => $reviews,
            'status' => $status ?: ProductReview::PENDING,
            'pendingCount' => ProductReview::pending()->count(),
        ]);
    }

    public function approve(ProductReview $review): RedirectResponse
    {
        return $this->moderate($review, ProductReview::APPROVED, __('تم اعتماد التقييم ونشره.'));
    }

    public function reject(Request $request, ProductReview $review): RedirectResponse
    {
        $note = $request->validate(['moderation_note' => ['nullable', 'string', 'max:255']]);

        return $this->moderate($review, ProductReview::REJECTED, __('تم رفض التقييم.'), $note['moderation_note'] ?? null);
    }

    public function destroy(ProductReview $review): RedirectResponse
    {
        $this->authorize('delete', $review);
        $review->delete();

        return back()->with('success', __('تم حذف التقييم.'));
    }

    private function moderate(ProductReview $review, string $status, string $message, ?string $note = null): RedirectResponse
    {
        $this->authorize('update', $review);

        $review->update([
            'status' => $status,
            'moderated_by' => auth()->id(),
            'moderated_at' => now(),
            'moderation_note' => $note,
        ]);

        return back()->with('success', $message);
    }
}
