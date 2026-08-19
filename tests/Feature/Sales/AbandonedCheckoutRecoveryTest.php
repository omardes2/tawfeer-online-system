<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Models\CheckoutSession;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\CheckoutRecoveryService;
use App\Modules\Store\Services\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * استرداد الطلبات غير المكتملة.
 *
 * ما تحرسه هذه الاختبارات هو الفائدة نفسها: زبونٌ **ضيف** كتب رقمه في الإتمام
 * ثم تردّد يجب أن يظهر في قائمة الاتصال — فمتابعة السلال المهجورة تشترط عميلًا
 * مسجَّلًا، ومعظم المشترين ضيوف، فكان هؤلاء غير مرئيّين أصلًا.
 *
 * والحارس الثاني ألّا يُزعج أحدٌ بلا داعٍ: من اشترى لاحقًا لا يُتصل به، ومن عاد
 * مرّاتٍ لا يظهر مرّات.
 */
class AbandonedCheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutRecoveryService $service;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $this->service = app(CheckoutRecoveryService::class);
    }

    /** جلسة إتمام ضيفٍ متروكة، بلا أي سجلّ عميل. */
    private function abandoned(string $phone = '0599123456', float $price = 100, int $qty = 1): CheckoutSession
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, 40);

        $cart = Cart::create([
            'session_token' => (string) Str::uuid(),
            'branch_id' => Branch::default()->id,
            'status' => 'active',
        ]);
        app(CartService::class)->addItem($cart, $variant->fresh(), $qty);

        $session = app(CheckoutService::class)->start($cart->fresh('items'));
        $session->update([
            'customer_name' => 'زبون متردّد',
            'customer_phone' => $phone,
            'shipping_address' => 'شارع الإرسال',
        ]);

        // ساعةٌ مضت: من هو الآن في منتصف الشراء لا يُزعَج.
        $session->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();

        return $session->fresh();
    }

    /** @return array<string, mixed>|null */
    private function row(string $phone, string $status = 'all'): ?array
    {
        return $this->service->list(DateRange::resolve('month'), ['status' => $status])
            ->firstWhere('phone', $phone);
    }

    private function sell(string $phone): void
    {
        app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون متردّد',
            'customer_phone' => $phone,
        ], [[
            'variant_id' => Product::factory()->create()->defaultVariant->id,
            'qty' => 1,
            'unit_price' => 100,
        ]], 2026);
    }

    // ────────── الظهور ──────────

    /** الضيف الذي كتب رقمه ولم يُكمل يظهر في قائمة الاتصال. */
    public function test_a_guest_who_left_a_phone_appears_in_the_call_list(): void
    {
        $this->abandoned();

        $row = $this->row('0599123456');

        $this->assertNotNull($row);
        $this->assertSame('new', $row['status']);
        $this->assertTrue($row['is_open']);
        $this->assertEqualsWithDelta(100.0, $row['value'], 0.01);
    }

    /** ومن هو الآن في منتصف الشراء لا يظهر. */
    public function test_a_checkout_still_in_progress_is_not_listed(): void
    {
        $session = $this->abandoned();
        $session->forceFill(['updated_at' => now()])->save();

        $this->assertNull($this->row('0599123456'));
    }

    /** والسلة التي تحوّلت إلى طلب ليست طلبًا ضائعًا. */
    public function test_a_converted_cart_is_not_listed(): void
    {
        $session = $this->abandoned();
        $session->cart->update(['status' => 'converted']);

        $this->assertNull($this->row('0599123456'));
    }

    // ────────── عدم إزعاج أحد ──────────

    /**
     * ومن اشترى لاحقًا بالرقم نفسه يظهر «تحوّل إلى طلب» ويخرج من قائمة الاتصال.
     *
     * هذا أهمّ حارس في الملف: مكالمةُ بيعٍ لمن اشترى بالفعل تُحرج الشركة.
     */
    public function test_a_customer_who_bought_later_is_marked_recovered(): void
    {
        $this->abandoned();
        $this->sell('0599123456');

        $row = $this->row('0599123456');

        $this->assertSame('recovered', $row['status']);
        $this->assertFalse($row['is_open']);
        $this->assertNotNull($row['recovered_order']);
        $this->assertEmpty($this->service->list(DateRange::resolve('month'), ['status' => 'open']));
    }

    /** والمطابقة لا تنكسر باختلاف صيغة الرقم بين «0599…» و«970599…». */
    public function test_the_match_survives_the_local_and_international_phone_forms(): void
    {
        $this->abandoned('0599123456');
        $this->sell('970599123456');

        $this->assertSame('recovered', $this->row('0599123456')['status']);
    }

    /** ومن عاد مرّاتٍ ولم يُكمل يظهر صفًّا واحدًا لا ثلاثة. */
    public function test_repeat_attempts_by_one_phone_collapse_into_one_row(): void
    {
        $this->abandoned('0599123456');
        $this->abandoned('0599123456');

        $rows = $this->service->list(DateRange::resolve('month'), ['status' => 'all']);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()['sessions']);
    }

    // ────────── تسجيل النتيجة ──────────

    /** تسجيل النتيجة يحفظ الحالة وصاحبها ويزيد عدّاد المحاولات. */
    public function test_recording_an_outcome_keeps_who_called_and_counts_the_attempt(): void
    {
        $session = $this->abandoned();
        $actor = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->service->markOutcome($session, 'no_answer', 'لم يرد مرّتين', $actor);

        $row = $this->row('0599123456');

        $this->assertSame('no_answer', $row['status']);
        $this->assertSame(1, $row['attempts']);
        $this->assertSame('لم يرد مرّتين', $row['note']);
        $this->assertNotNull($row['contacted_at']);
    }

    /** و«رفض» يخرج من قائمة الاتصال. */
    public function test_a_refusal_leaves_the_call_list(): void
    {
        $session = $this->abandoned();
        $actor = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->service->markOutcome($session, 'refused', null, $actor);

        $this->assertFalse($this->row('0599123456')['is_open']);
    }

    // ────────── الشاشة ──────────

    /** الشاشة تفتح لموظف المبيعات — وهو من يتّصل. */
    public function test_the_page_opens_for_a_sales_user(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('sales');

        $this->actingAs($user)->get(route('admin.sales.abandoned_checkouts.index'))->assertOk();
    }

    /** وتُمنع عن المستودع. */
    public function test_the_page_is_forbidden_without_the_permission(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('warehouse');

        $this->actingAs($user)->get(route('admin.sales.abandoned_checkouts.index'))->assertForbidden();
    }

    /** والمسوّق لا يرى قائمة زبائن المتجر كلّه. */
    public function test_an_affiliate_cannot_see_the_store_wide_lead_list(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('affiliate');

        $this->actingAs($user)->get(route('admin.sales.abandoned_checkouts.index'))->assertForbidden();
    }

    /** وتسجيل النتيجة من الشاشة يمرّ ويُحفظ. */
    public function test_the_outcome_form_saves(): void
    {
        $session = $this->abandoned();
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.sales.abandoned_checkouts.outcome', $session->uuid), [
                'recovery_status' => 'contacted',
                'recovery_note' => 'سيؤكّد غدًا',
            ])
            ->assertRedirect();

        $this->assertSame('contacted', $session->fresh()->recovery_status);
    }
}
