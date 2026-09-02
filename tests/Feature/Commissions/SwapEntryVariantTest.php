<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionTransition;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تبديل صنف حركات عمولة مسوّق — الكشف وحده.
 *
 * ## الحاجة
 *
 * صنفٌ بِيع باسمٍ ثم صار يُباع باسمٍ آخر. كشف المسوّق يبقى على الاسم القديم
 * فيُقرأ صنفين وهو واحد، ويُحسب ربحه على سعر جملةٍ لم يعد قائمًا.
 *
 * ## وما تحرسه هذه الاختبارات
 *
 * أنّ التبديل **لا يخرج من `commission_entries`**: الفاتورة وبنودها والمخزون
 * والإيراد وتكلفة المبيعات تبقى كما هي — البضاعة خرجت على الصنف القديم فعلًا،
 * وإعادةُ كتابة ذلك تجعل المخزون مخصومًا من صنفٍ والفاتورة على صنفٍ آخر.
 *
 * وأنّ **سعر الجملة صفرًا يُرفض**: الصفر هنا «غير معروف» لا «مجّاني»، وأساس
 * عمولة المسوّق هو الهامش — فجملةٌ صفر تجعل الهامش سعرَ البيع كاملًا وتُضخّم
 * المستحقّ. وهو نفس حارس إعادة الاحتساب القائم.
 *
 * وأنّ **المدفوعة يُبدَّل وسمُها ولا يُمَسّ مبلغها**: سند الصرف يحمل ما خرج من
 * الخزينة.
 */
class SwapEntryVariantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    private Product $oldProduct;

    private Product $newProduct;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->affiliate = User::factory()->create(['name' => 'سائد شاهين']);
        $this->actingAs($this->admin);

        $this->warehouse = Warehouse::firstOrFail();

        // القديم: يُباع بـ٣٣ وجملته ١٨ ⇒ هامش ١٥.
        $this->oldProduct = $this->product('عطر 250 ملم', 33, 18);
        // الجديد: جملته ١٢ ⇒ هامش ٢١ على نفس سعر البيع.
        $this->newProduct = $this->product('عطر سمارت', 33, 12);
    }

    private function product(string $name, float $retail, float $wholesale): Product
    {
        $product = Product::factory()->create([
            'name' => $name, 'retail_price' => $retail, 'wholesale_price' => $wholesale,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($product->defaultVariant, $this->warehouse, 100, 10);

        return $product;
    }

    private function order(Product $product, float $unitPrice = 33, int $qty = 1): Order
    {
        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
        ], [[
            'variant_id' => $product->defaultVariant->id, 'qty' => $qty, 'unit_price' => $unitPrice,
        ]], (int) now()->year);
    }

    /** حركة عمولة على بندٍ حقيقي بالصنف القديم. */
    private function entry(string $state = 'eligible', float $amount = 15, float $unitPrice = 33, int $qty = 1): CommissionEntry
    {
        $order = $this->order($this->oldProduct, $unitPrice, $qty);
        $item = $order->items()->firstOrFail();

        return CommissionEntry::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'variant_id' => $this->oldProduct->defaultVariant->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => $state,
            'basis_amount' => $amount,
            'basis' => $amount,
            'rate' => 1,
            'amount' => $amount,
            'wholesale_cost_snapshot' => 18,
            'rule_snapshot' => ['method' => 'margin'],
        ]);
    }

    private function runSwap(bool $apply = true, ?ProductVariant $to = null): array
    {
        return app(CommissionService::class)->swapEntryVariant(
            $this->affiliate,
            [$this->oldProduct->defaultVariant->id],
            $to ?? $this->newProduct->defaultVariant,
            $this->admin,
            $apply,
        );
    }

    // ────────── التبديل وإعادة الاحتساب ──────────

    /** **الصنف يتبدّل والمبلغ يُعاد احتسابه على جملة الصنف الجديد.** */
    public function test_the_variant_is_swapped_and_the_amount_recomputed(): void
    {
        $entry = $this->entry();

        $this->runSwap();
        $entry->refresh();

        $this->assertSame($this->newProduct->defaultVariant->id, $entry->variant_id);
        $this->assertSame('21.00', $entry->amount);          // ٣٣ − ١٢
        $this->assertSame('12.00', $entry->wholesale_cost_snapshot);
    }

    /** والكميّة تدخل الحساب — الهامش قيمةُ السطر لا الوحدة. */
    public function test_the_margin_multiplies_by_quantity(): void
    {
        $entry = $this->entry('eligible', 30, 33, 2);

        $this->runSwap();

        $this->assertSame('42.00', $entry->fresh()->amount);  // (٣٣ − ١٢) × ٢
    }

    /** **والعرض التجريبي لا يكتب شيئًا** — وهو نفس ما سيُنفَّذ. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $entry = $this->entry();

        $changes = $this->runSwap(apply: false);

        $this->assertCount(1, $changes);
        $this->assertSame(21.0, $changes[0]['now']);
        $this->assertSame(6.0, $changes[0]['delta']);

        $entry->refresh();
        $this->assertSame($this->oldProduct->defaultVariant->id, $entry->variant_id);
        $this->assertSame('15.00', $entry->amount);
    }

    // ────────── ما لا يُمَسّ ──────────

    /**
     * **الفاتورة والمخزون لا يُمَسّان.**
     *
     * البضاعة خرجت على الصنف القديم فعلًا: بندُ الطلب يبقى عليه، ولقطةُ جملته
     * كما هي، وأرصدةُ المخزون لا تتحرّك.
     */
    public function test_the_order_item_and_stock_are_untouched(): void
    {
        $entry = $this->entry();
        $item = OrderItem::findOrFail($entry->order_item_id);
        $stockBefore = $this->stock($this->oldProduct);
        $newStockBefore = $this->stock($this->newProduct);

        $this->runSwap();

        $this->assertSame($this->oldProduct->defaultVariant->id, $item->fresh()->variant_id);
        $this->assertSame($stockBefore, $this->stock($this->oldProduct));
        $this->assertSame($newStockBefore, $this->stock($this->newProduct));
    }

    private function stock(Product $product): float
    {
        return (float) InventoryStock::where('variant_id', $product->defaultVariant->id)
            ->sum('qty_on_hand');
    }

    /** **والمدفوعة يُبدَّل وسمُها ولا يتغيّر مبلغها.** */
    public function test_a_paid_entry_is_relabelled_but_its_amount_is_kept(): void
    {
        $entry = $this->entry('paid');

        $changes = $this->runSwap();
        $entry->refresh();

        $this->assertTrue($changes[0]['relabel_only']);
        $this->assertSame('paid', $changes[0]['reason']);
        $this->assertSame($this->newProduct->defaultVariant->id, $entry->variant_id);
        $this->assertSame('15.00', $entry->amount);
        $this->assertSame('18.00', $entry->wholesale_cost_snapshot);
    }

    /** والعمولة الثابتة كذلك — لا تتعلّق بالهامش أصلًا. */
    public function test_a_fixed_commission_keeps_its_amount(): void
    {
        $entry = $this->entry();
        $entry->update(['rule_snapshot' => ['method' => 'fixed']]);

        $this->runSwap();

        $this->assertSame('15.00', $entry->fresh()->amount);
        $this->assertSame($this->newProduct->defaultVariant->id, $entry->fresh()->variant_id);
    }

    /** والمعكوسة والملغاة خارج النطاق أصلًا. */
    public function test_reversed_entries_are_not_selected(): void
    {
        $entry = $this->entry('reversed');

        $this->assertSame([], $this->runSwap());
        $this->assertSame($this->oldProduct->defaultVariant->id, $entry->fresh()->variant_id);
    }

    /** ولا تُمَسّ حركات مسوّقٍ آخر على الصنف نفسه. */
    public function test_another_earners_entries_are_not_touched(): void
    {
        $other = User::factory()->create(['name' => 'مسوّق آخر']);
        $entry = $this->entry();
        // `earner_id` محروسٌ بعدم القابلية للتعديل — فيُنشأ بصاحبه لا يُنقل إليه.
        CommissionEntry::whereKey($entry->id)->toBase()->update(['earner_id' => $other->id]);

        $this->assertSame([], $this->runSwap());
        $this->assertSame($this->oldProduct->defaultVariant->id, $entry->fresh()->variant_id);
    }

    // ────────── حارس الصفر ──────────

    /**
     * **سعر جملةٍ صفر يُرفض.**
     *
     * الصفر «غير معروف» لا «مجّاني»: أساس عمولة المسوّق هو الهامش، فجملةٌ صفر
     * تجعل الهامش سعرَ البيع كاملًا ويتضخّم المستحقّ.
     */
    public function test_a_zero_wholesale_target_is_refused(): void
    {
        $entry = $this->entry();
        $free = $this->product('عطر بلا سعر', 33, 0);

        try {
            $this->runSwap(to: $free->defaultVariant);
            $this->fail('كان يجب أن يُرفض.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('غير معروف', $e->validator->errors()->first());
        }

        $this->assertSame($this->oldProduct->defaultVariant->id, $entry->fresh()->variant_id);
        $this->assertSame('15.00', $entry->fresh()->amount);
    }

    // ────────── الأثر مُدوَّن ──────────

    /** **وكل تبديل يُدوَّن** بقيمته السابقة والجديدة. */
    public function test_every_swap_is_recorded(): void
    {
        $entry = $this->entry();

        $this->runSwap();

        $transition = CommissionTransition::where('commission_entry_id', $entry->id)->latest('id')->firstOrFail();
        $this->assertSame('variant_swap', $transition->reference);
        $this->assertSame($this->admin->id, $transition->actor_id);
        $this->assertStringContainsString('عطر سمارت', $transition->note);
        $this->assertStringContainsString('15.00', $transition->note);
        $this->assertStringContainsString('21.00', $transition->note);
    }

    // ────────── الأمر ──────────

    public function test_the_command_previews_without_writing(): void
    {
        $entry = $this->entry();

        $this->artisan('commissions:swap-entry-variant', [
            'earner' => (string) $this->affiliate->id,
            'from' => (string) $this->oldProduct->defaultVariant->id,
            'to' => (string) $this->newProduct->defaultVariant->id,
        ])->assertSuccessful();

        $this->assertSame('15.00', $entry->fresh()->amount);
    }

    public function test_the_command_applies_with_the_flag(): void
    {
        $entry = $this->entry();

        $this->artisan('commissions:swap-entry-variant', [
            'earner' => (string) $this->affiliate->id,
            'from' => (string) $this->oldProduct->defaultVariant->id,
            'to' => (string) $this->newProduct->defaultVariant->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('21.00', $entry->fresh()->amount);
    }

    /** واسمٌ يطابق أكثر من متغيّر يُرفض بدل أن يُخمَّن. */
    public function test_an_ambiguous_target_is_refused(): void
    {
        $this->entry();
        ProductVariant::factory()->create(['product_id' => $this->newProduct->id, 'wholesale_price' => 12]);

        $this->artisan('commissions:swap-entry-variant', [
            'earner' => (string) $this->affiliate->id,
            'from' => 'عطر 250 ملم',
            'to' => 'عطر سمارت',
        ])->assertFailed();
    }
}
