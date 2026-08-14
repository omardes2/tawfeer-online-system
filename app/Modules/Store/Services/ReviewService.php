<?php

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Crm\Models\Customer;
use App\Modules\Sales\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * تقييمات المنتجات (قراءة للمتجر + إنشاء من الزبون).
 *
 * قاعدتان تحكمان كل شيء:
 *  1. لا يكتب إلا من **استلم** المنتج — التقييم مربوط بطلب `delivered` يحوي
 *     أحد متغيّرات المنتج. يمنع السبام ويجعل كل رأي عن تجربة حقيقية.
 *  2. لا يُعرض إلا **المعتمَد** — الجديد يبدأ `pending` بانتظار مراجعة إدارية.
 *
 * الاعتماد نفسه يقع في وحدة الإدارة لا هنا: هذه طبقة المتجر.
 */
class ReviewService
{
    /** ملخّص التقييم: المعدّل والعدد وتوزيع النجوم. */
    public function summary(Product $product): array
    {
        $rows = ProductReview::query()
            ->approved()
            ->where('product_id', $product->id)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $count = (int) $rows->sum();
        // توزيع كامل من 5 إلى 1 حتى لا يقفز الرسم بغياب درجة.
        $breakdown = [];
        foreach ([5, 4, 3, 2, 1] as $star) {
            $n = (int) ($rows[$star] ?? 0);
            $breakdown[$star] = ['count' => $n, 'percent' => $count > 0 ? round($n / $count * 100) : 0];
        }

        $sum = 0;
        foreach ($rows as $star => $n) {
            $sum += (int) $star * (int) $n;
        }

        return [
            'count' => $count,
            'average' => $count > 0 ? round($sum / $count, 1) : 0.0,
            'breakdown' => $breakdown,
        ];
    }

    /** @return LengthAwarePaginator<int, ProductReview> */
    public function approved(Product $product, int $perPage = 5): LengthAwarePaginator
    {
        return ProductReview::query()
            ->approved()
            ->where('product_id', $product->id)
            ->with('customer:id,name')
            ->latest()
            ->paginate($perPage, ['*'], 'reviews')
            ->withQueryString();
    }

    /**
     * الطلب المستلَم الذي يخوّل هذا الزبون تقييم هذا المنتج، أو `null`.
     *
     * `delivered` لا `shipped`: الرأي عن منتج بيد صاحبه، ومن لم يستلمه بعد
     * لا رأي له فيه.
     */
    public function purchaseOrder(Customer $customer, Product $product): ?Order
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'delivered')
            ->whereHas('items.variant', fn (Builder $q) => $q->where('product_id', $product->id))
            ->latest('delivered_at')
            ->first();
    }

    /** تقييم هذا الزبون لهذا المنتج إن وُجد (بأي حالة). */
    public function existing(Customer $customer, Product $product): ?ProductReview
    {
        return ProductReview::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->first();
    }

    /**
     * إنشاء تقييم معلّق.
     *
     * @param  array{rating: int, title?: ?string, body?: ?string}  $data
     */
    public function create(Customer $customer, Product $product, Order $order, array $data): ProductReview
    {
        return ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'rating' => (int) $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'status' => ProductReview::PENDING,
        ]);
    }
}
