<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * الرصيد الافتتاحي للمورد.
 *
 * العمود كان موجودًا منذ البداية ويُقبل في النموذج، لكنه **لم يُرحَّل قط**:
 * رقمٌ على الصفّ يظهر في قائمة الموردين وصفحتهم، ولا أثر له في دليل الحسابات.
 * فيقول الجدولُ إن علينا مبلغًا لا يعرفه ميزان المراجعة.
 *
 * والاتجاه معكوسٌ عن العميل: الموجب يعني أننا **مدينون للمورد** ⇒ دائن ذمّته
 * (خصم) / مدين رأس المال.
 */
class SupplierOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private SupplierService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(SupplierService::class);
    }

    private function supplier(float $opening = 0): Supplier
    {
        return $this->service->create([
            'name' => 'مورد الصين',
            'opening_balance' => $opening,
        ]);
    }

    private function balance(string $code): float
    {
        $lines = Account::where('code', $code)->firstOrFail()
            ->lines()->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->get();

        return round($lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);
    }

    private function equityCode(): string
    {
        return config('accounting.opening.equity_account');
    }

    // ────────── القيد ──────────

    /** الرصيد الافتتاحي يُقيَّد دائنًا على ذمّة المورد ومدينًا على رأس المال. */
    public function test_an_opening_balance_credits_the_supplier_and_debits_capital(): void
    {
        $supplier = $this->supplier(5000);

        $this->assertNotNull($supplier->opening_entry_id);
        // الذمّة خصم: الدائن يزيدها، فالفرق (مدين − دائن) سالب.
        $this->assertEqualsWithDelta(-5000.0, $this->balance($supplier->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(5000.0, $this->balance($this->equityCode()), 0.01);
    }

    /** والسالب ينعكس طرفاه — دفعةٌ مقدَّمة منّا للمورد. */
    public function test_a_negative_opening_balance_debits_the_supplier(): void
    {
        $supplier = $this->supplier(-400);

        $this->assertEqualsWithDelta(400.0, $this->balance($supplier->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(-400.0, $this->balance($this->equityCode()), 0.01);
    }

    /** والصفر لا يُنشئ قيدًا بلا أثر. */
    public function test_a_zero_opening_balance_posts_nothing(): void
    {
        $supplier = $this->supplier(0);

        $this->assertNull($supplier->opening_entry_id);
        $this->assertSame(0, JournalEntry::where('source', 'supplier_opening')->count());
    }

    // ────────── الحارس ──────────

    /** حفظٌ ثانٍ بالقيمة نفسها لا يُرحّل مرّة أخرى. */
    public function test_saving_again_does_not_post_a_second_entry(): void
    {
        $supplier = $this->supplier(5000);

        $this->service->update($supplier->fresh(), ['name' => 'مورد الصين', 'opening_balance' => 5000]);

        $this->assertSame(1, JournalEntry::where('source', 'supplier_opening')->count());
        $this->assertEqualsWithDelta(-5000.0, $this->balance($supplier->glAccount->code), 0.01);
    }

    /** وحفظٌ بلا الحقل أصلًا لا يمحو رصيدًا مُرحّلًا. */
    public function test_saving_without_the_field_keeps_the_balance(): void
    {
        $supplier = $this->supplier(5000);

        $this->service->update($supplier->fresh(), ['name' => 'مورد الصين للتجارة']);

        $this->assertEqualsWithDelta(5000.0, (float) $supplier->fresh()->opening_balance, 0.01);
        $this->assertEqualsWithDelta(-5000.0, $this->balance($supplier->glAccount->code), 0.01);
    }

    /** وتغيير الرقم يعكس الأصل ويُرحّل مصحَّحًا. */
    public function test_changing_the_amount_reverses_and_reposts(): void
    {
        $supplier = $this->supplier(5000);
        $first = $supplier->opening_entry_id;

        $this->service->update($supplier->fresh(), ['opening_balance' => 3000]);
        $updated = $supplier->fresh();

        $this->assertNotSame($first, $updated->opening_entry_id);
        $this->assertTrue(JournalEntry::findOrFail($first)->isReversed());
        $this->assertEqualsWithDelta(-3000.0, $this->balance($updated->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(3000.0, $this->balance($this->equityCode()), 0.01);
    }

    /**
     * ورصيدٌ قديم مكتوبٌ بلا قيد يُرحَّل عند أول حفظ — ولو لم تتغيّر قيمته.
     *
     * هذه حالة ما قبل المرحلة: العمود كان يُملأ إسنادًا جماعيًا بلا قيد. ولو
     * اكتفى الحارس بـ«لم يتغيّر» لبقي ذلك الرقم خارج الدفاتر إلى الأبد.
     */
    public function test_a_legacy_unposted_balance_is_posted_on_the_next_save(): void
    {
        $supplier = $this->supplier(0);
        $supplier->forceFill(['opening_balance' => 750, 'opening_entry_id' => null])->save();

        $this->assertTrue($supplier->fresh()->hasUnpostedOpening());

        $this->service->update($supplier->fresh(), ['opening_balance' => 750]);
        $updated = $supplier->fresh();

        $this->assertFalse($updated->hasUnpostedOpening());
        $this->assertEqualsWithDelta(-750.0, $this->balance($updated->glAccount->code), 0.01);
    }

    // ────────── الصلاحية ──────────

    /**
     * من لا يملك صلاحية القيود لا يُحرّك الدفاتر من شاشة المورد.
     *
     * بدورٍ يُنشئ الموردين ولا يُرحّل قيودًا — وهي الحالة التي تُبنى من أجلها
     * البوّابة أصلًا: لا يوجد في البذرة اليوم دورٌ كهذا، لكن الأدوار تُصنع من
     * اللوحة، فالحارس لا يجوز أن يعتمد على غيابها.
     */
    public function test_a_user_without_journal_permission_cannot_set_it(): void
    {
        $role = Role::findOrCreate('purchasing-only', 'web');
        $role->givePermissionTo(['purchasing.suppliers.view', 'purchasing.suppliers.create']);

        $buyer = User::factory()->create(['branch_id' => Branch::default()->id]);
        $buyer->assignRole($role);

        $this->actingAs($buyer)->post(route('admin.purchasing.suppliers.store'), [
            'name' => 'مورد جديد',
            'opening_balance' => 900,
        ])->assertRedirect();

        $supplier = Supplier::where('name', 'مورد جديد')->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $supplier->opening_balance, 0.01);
        $this->assertSame(0, JournalEntry::where('source', 'supplier_opening')->count());
    }

    /** ومن يملكها يُدخله من الشاشة فيُرحَّل. */
    public function test_an_authorized_user_posts_it_from_the_form(): void
    {
        $this->post(route('admin.purchasing.suppliers.store'), [
            'name' => 'مورد قديم',
            'opening_balance' => 1200,
        ])->assertRedirect();

        $supplier = Supplier::where('name', 'مورد قديم')->firstOrFail();

        $this->assertNotNull($supplier->opening_entry_id);
        $this->assertEqualsWithDelta(-1200.0, $this->balance($supplier->glAccount->code), 0.01);
    }
}
