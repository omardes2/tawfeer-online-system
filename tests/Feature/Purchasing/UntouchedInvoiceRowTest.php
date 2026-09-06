<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بندٌ أُضيف ولم يُملأ لا يمنع حفظ فاتورة الشراء.
 *
 * ## العطب
 *
 * زرّ «إضافة بند» يضع صفًّا بكميّة ١ وكلفة صفر. فالصفّ الذي يضيفه المستخدم ثم
 * يتركه **لا يبدو فارغًا للتحقّق**: يمرّ على `qty > 0` ويسقط على اسم الصنف —
 * فتظهر رسالة:
 *
 *     The items.10.new_name field is required when none of
 *     items.10.variant_id / items.10.description are present
 *
 * إنجليزيةٌ بلغة القواعد، لا تدلّ على صفٍّ في الشاشة ولا تقول ماذا يُفعل. ومع
 * فاتورةٍ بأحد عشر بندًا يبحث المستخدم عن «الصفّ العاشر» ولا يجده — الترقيم
 * يبدأ من صفر.
 *
 * ## والحدّ الذي لا يُتجاوز
 *
 * يُسقَط الصفّ إن لم يُعرّف صنفًا **ولم يحمل مبلغًا كُتب**. فرقمٌ كتبه المستخدم
 * لا يُمحى بصمت: يُرفض برسالةٍ تُسمّي صفَّه، لأن كلفةً بلا صنفٍ تُقيَّد عليه لا
 * تدخل المخزون ولا تُسعّر شيئًا.
 */
class UntouchedInvoiceRowTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->supplier = Supplier::factory()->create();
    }

    /** الصفّ كما يبنيه النموذج قبل أن يُلمس: كميّة ١ وكلفة صفر. */
    private function untouchedRow(): array
    {
        return [
            'variant_id' => '', 'new_name' => '', 'description' => '', 'sell_price' => 0,
            'qty' => 1, 'unit_cost' => 0, 'tax_rate' => 0,
            'unit_price_foreign' => 0, 'cbm_per_unit' => 0, 'landed_unit_cost' => 0,
        ];
    }

    private function filledRow(array $overrides = []): array
    {
        return array_replace([
            'variant_id' => ProductVariant::factory()->create()->id,
            'qty' => 3, 'unit_cost' => 120, 'tax_rate' => 0,
        ], $overrides);
    }

    private function post_(array $items)
    {
        return $this->post(route('admin.purchasing.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'ILS',
            'items' => $items,
        ]);
    }

    // ────────── يُسقَط ولا يمنع ──────────

    /** **الصفّ غير المملوء يُسقط والفاتورة تُحفظ.** */
    public function test_an_untouched_row_does_not_block_the_save(): void
    {
        $this->post_([$this->filledRow(), $this->untouchedRow()])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice = PurchaseInvoice::latest('id')->firstOrFail();

        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(360.0, (float) $invoice->subtotal);
    }

    /** ولو كان في آخر فاتورةٍ طويلة — وهو موضعه المعتاد. */
    public function test_a_trailing_untouched_row_among_many_is_dropped(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = $this->filledRow(['qty' => 1, 'unit_cost' => 10]);
        }
        $items[] = $this->untouchedRow();

        $this->post_($items)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(10, PurchaseInvoice::latest('id')->firstOrFail()->items()->count());
    }

    // ────────── وما كُتب فيه رقم لا يُمحى ──────────

    /**
     * **بندٌ بكلفةٍ ولا صنف يُرفض** — لا يُسقَط بصمت.
     *
     * كلفةٌ بلا صنفٍ تُقيَّد عليه لا تدخل المخزون ولا تُسعّر شيئًا، وإسقاطُها
     * يجعل المستخدم يظنّها حُفظت.
     */
    public function test_a_priced_row_without_an_item_is_refused_not_dropped(): void
    {
        $orphan = $this->untouchedRow();
        $orphan['unit_cost'] = 250;

        $this->post_([$this->filledRow(), $orphan])
            ->assertSessionHasErrors('items.1.new_name');

        $this->assertSame(0, PurchaseInvoice::count());
    }

    /** **والرسالة عربية وتُسمّي رقم البند** — لا `items.1.new_name`. */
    public function test_the_message_is_arabic_and_names_the_row(): void
    {
        $orphan = $this->untouchedRow();
        $orphan['unit_cost'] = 250;

        $errors = $this->post_([$this->filledRow(), $orphan])
            ->assertSessionHasErrors()
            ->getSession()->get('errors');

        $message = $errors->first('items.1.new_name');

        $this->assertStringContainsString('البند رقم 2', $message);
        $this->assertStringNotContainsString('items.', $message);
        $this->assertStringNotContainsString('field is required', $message);
    }

    // ────────── الحدود ──────────

    /** وفاتورةٌ كل بنودها غير مملوءة تُرفض برسالةٍ عربية مفهومة. */
    public function test_an_invoice_of_only_untouched_rows_is_refused_in_arabic(): void
    {
        $errors = $this->post_([$this->untouchedRow(), $this->untouchedRow()])
            ->assertSessionHasErrors('items')
            ->getSession()->get('errors');

        $this->assertStringContainsString('بندًا واحدًا على الأقل', $errors->first('items'));
        $this->assertSame(0, PurchaseInvoice::count());
    }

    /** والوصف الحرّ وحده يُعرّف بندًا — بندُ مصاريفَ بلا صنف في الكتالوج. */
    public function test_a_description_only_row_is_kept(): void
    {
        $row = $this->untouchedRow();
        $row['description'] = 'أجور تخليص';
        $row['unit_cost'] = 400;

        $this->post_([$row])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, PurchaseInvoice::latest('id')->firstOrFail()->items()->count());
    }

    /** واسم صنفٍ جديد كذلك. */
    public function test_a_new_name_row_is_kept(): void
    {
        $row = $this->untouchedRow();
        $row['new_name'] = 'صنف جديد من الفاتورة';
        $row['unit_cost'] = 55;
        $row['sell_price'] = 90;

        $this->post_([$row])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, PurchaseInvoice::latest('id')->firstOrFail()->items()->count());
    }
}
