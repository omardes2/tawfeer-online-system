<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Crm\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Store\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تقييمات المنتجات وآراء الزبائن.
 *
 * قاعدتان تحكمان الميزة، وكلتاهما مفروضتان في الخادم لا في الواجهة:
 *  1. لا يكتب إلا من **استلم** المنتج (طلب `delivered` يحوي أحد متغيّراته).
 *  2. لا يُعرض إلا **المعتمَد** — الجديد `pending` حتى تعتمده الإدارة.
 *
 * إخفاء النموذج في الواجهة تحسينُ عرض؛ الاختبارات تطرق النقطة مباشرةً.
 */
class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'منتج للتجربة'): Product
    {
        return Product::factory()->active()->create(['name' => $name, 'visibility' => 'visible']);
    }

    /** زبون مربوط بمستخدم — التقييم يمرّ عبر جلسة الويب. */
    private function customer(): Customer
    {
        $user = User::factory()->create();

        return Customer::factory()->create(['user_id' => $user->id, 'name' => 'عمر التجريبي']);
    }

    /** طلب مستلَم يحوي المنتج — دليل الشراء الذي يفتح باب التقييم. */
    private function deliveredOrder(Customer $customer, Product $product): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'variant_id' => $product->defaultVariant->id,
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        return $order;
    }

    /** الاسم ليس `post`: يصطدم بـ`TestCase::post()` ويستدعي نفسه. */
    private function submitReview(Product $product, array $data = []): TestResponse
    {
        return $this->post(
            route('storefront.product.reviews.store', $product->slug),
            $data + ['rating' => 5, 'body' => 'منتج ممتاز وسريع الوصول.']
        );
    }

    public function test_a_buyer_who_received_the_product_can_review_it(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->deliveredOrder($customer, $product);

        $this->actingAs($customer->user)->submitReview($product)->assertRedirect();

        $review = ProductReview::firstOrFail();
        $this->assertSame($product->id, $review->product_id);
        $this->assertSame($customer->id, $review->customer_id);
        $this->assertSame($order->id, $review->order_id);
        // معلّق لا منشور: المتجر لا يعرض شيئًا كتبه طرف خارجي بلا مراجعة.
        $this->assertSame(ProductReview::PENDING, $review->status);
    }

    public function test_someone_who_never_bought_it_cannot_review(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $this->actingAs($customer->user)->submitReview($product)->assertSessionHasErrors('rating');
        $this->assertSame(0, ProductReview::count());
    }

    public function test_an_order_that_has_not_arrived_yet_does_not_grant_the_right(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->deliveredOrder($customer, $product);
        // شُحن ولم يُستلم: لا رأي في منتج لم يصل صاحبه بعد.
        $order->update(['status' => 'shipped', 'delivered_at' => null]);

        $this->actingAs($customer->user)->submitReview($product)->assertSessionHasErrors('rating');
        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_guest_is_turned_away(): void
    {
        $product = $this->product();

        // التحويل إلى `login` لا `account.login`: سلوك `auth` العام في التطبيق
        // كلّه (المفضّلة والطلبات والعناوين مثله) — لا يُغيَّر من هنا.
        $this->submitReview($product)->assertRedirect(route('login'));
        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_customer_cannot_review_the_same_product_twice(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $this->deliveredOrder($customer, $product);

        $this->actingAs($customer->user)->submitReview($product)->assertRedirect();
        $this->actingAs($customer->user)->submitReview($product, ['rating' => 1])->assertSessionHasErrors('rating');

        // رأي واحد لكل زبون: التكرار يرفع المعدّل صناعيًّا.
        $this->assertSame(1, ProductReview::count());
        $this->assertSame(5, ProductReview::first()->rating);
    }

    public function test_the_rating_must_be_between_one_and_five(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $this->deliveredOrder($customer, $product);

        foreach ([0, 6, -1] as $bad) {
            $this->actingAs($customer->user)->submitReview($product, ['rating' => $bad])->assertSessionHasErrors('rating');
        }
        $this->assertSame(0, ProductReview::count());
    }

    public function test_the_product_page_shows_approved_reviews_and_hides_the_rest(): void
    {
        $product = $this->product();

        ProductReview::factory()->approved()->create([
            'product_id' => $product->id, 'rating' => 5, 'body' => 'رأي معتمَد ظاهر',
        ]);
        ProductReview::factory()->create([
            'product_id' => $product->id, 'rating' => 1, 'body' => 'رأي معلّق مخفي',
        ]);
        ProductReview::factory()->rejected()->create([
            'product_id' => $product->id, 'rating' => 1, 'body' => 'رأي مرفوض مخفي',
        ]);

        $this->get(route('storefront.product', $product->slug))
            ->assertOk()
            ->assertSee('رأي معتمَد ظاهر', false)
            ->assertDontSee('رأي معلّق مخفي', false)
            ->assertDontSee('رأي مرفوض مخفي', false);
    }

    public function test_the_average_counts_only_approved_reviews(): void
    {
        $product = $this->product();

        // معتمَدان: 5 و3 → المعدّل 4.0. المعلّق (1) لا يُحتسب.
        ProductReview::factory()->approved()->create(['product_id' => $product->id, 'rating' => 5]);
        ProductReview::factory()->approved()->create(['product_id' => $product->id, 'rating' => 3]);
        ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 1]);

        $summary = app(ReviewService::class)->summary($product);

        $this->assertSame(2, $summary['count']);
        $this->assertSame(4.0, $summary['average']);
        $this->assertSame(1, $summary['breakdown'][5]['count']);
        $this->assertSame(0, $summary['breakdown'][1]['count']);
    }

    public function test_a_product_with_no_reviews_says_so_without_breaking(): void
    {
        $product = $this->product();

        $this->get(route('storefront.product', $product->slug))
            ->assertOk()
            ->assertSee(__('storefront.reviews_empty'), false);
    }
}
