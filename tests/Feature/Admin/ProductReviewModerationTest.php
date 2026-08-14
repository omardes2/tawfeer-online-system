<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use Database\Seeders\CatalogPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مراجعة التقييمات في لوحة الإدارة.
 *
 * الاعتماد هو ما ينشر الرأي في المتجر، فالوصول إليه محكوم بـRBAC لا باسم
 * مستخدم ولا بدور مكتوب في الكود (المبدأ 11). وكل قرار يُوقَّع بصاحبه ووقته.
 */
class ProductReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CatalogPermissionSeeder::class);
    }

    private function moderator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function review(): ProductReview
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible']);

        return ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 4]);
    }

    public function test_approving_publishes_the_review_and_records_who_did_it(): void
    {
        $moderator = $this->moderator();
        $review = $this->review();
        $review->update(['body' => 'نصّ معتمَد ظاهر']);

        $this->actingAs($moderator)
            ->patch(route('admin.reviews.approve', $review))
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ProductReview::APPROVED, $review->status);
        $this->assertSame($moderator->id, $review->moderated_by);
        $this->assertNotNull($review->moderated_at);

        // منشور فعلًا: نصّه يظهر في صفحة المنتج بعد أن كان محجوبًا.
        $this->get(route('storefront.product', $review->product->slug))
            ->assertOk()
            ->assertSee('نصّ معتمَد ظاهر', false);
    }

    public function test_rejecting_keeps_it_out_of_the_storefront(): void
    {
        $review = $this->review();
        $review->update(['body' => 'نصّ مرفوض']);

        $this->actingAs($this->moderator())
            ->patch(route('admin.reviews.reject', $review), ['moderation_note' => 'مخالف'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ProductReview::REJECTED, $review->status);
        $this->assertSame('مخالف', $review->moderation_note);

        $this->get(route('storefront.product', $review->product->slug))
            ->assertOk()
            ->assertDontSee('نصّ مرفوض', false);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $review = $this->review();
        // دور بلا صلاحيات الكتالوج: الرفض يأتي من السياسة لا من إخفاء الزرّ.
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('admin.reviews.index'))->assertForbidden();
        $this->actingAs($outsider)->patch(route('admin.reviews.approve', $review))->assertForbidden();

        $this->assertSame(ProductReview::PENDING, $review->fresh()->status);
    }

    public function test_the_queue_lists_pending_reviews_first_by_default(): void
    {
        $pending = $this->review();
        $approved = $this->review();
        $approved->update(['status' => ProductReview::APPROVED, 'body' => 'رأي معتمَد سابقًا']);

        $this->actingAs($this->moderator())
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertSee($pending->product->name, false)
            ->assertDontSee('رأي معتمَد سابقًا', false);
    }
}
