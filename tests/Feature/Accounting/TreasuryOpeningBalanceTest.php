<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الرصيد الافتتاحي للخزينة — بعد الإنشاء أيضًا.
 *
 * كان يُرحَّل مرّة واحدة لحظة الإنشاء ثم يُغلق البابُ: من نسيه لم يجد له مدخلًا
 * إلا قيدًا يدويًا يضبط الدفاتر ويترك عمود «افتتاحي» صفرًا، فيقرأ رقمين
 * متناقضين عن خزينةٍ واحدة ويظنّ أن شيئًا لم يُحتسب.
 */
class TreasuryOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private TreasuryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(TreasuryService::class);
    }

    private function box(float $opening = 0): Treasury
    {
        return $this->service->create([
            'code' => 'CB-TAHA', 'name' => 'صندوق طه', 'type' => 'cash', 'opening_balance' => $opening,
        ]);
    }

    private function openingEntries(Treasury $box): int
    {
        return JournalEntry::where('reference_type', 'treasury_opening')
            ->where('reference_id', $box->id)
            ->whereNull('reverses_entry_id')
            ->count();
    }

    // ────────── الإنشاء ──────────

    /** الرصيد الافتتاحي عند الإنشاء يُرحَّل ويُعرف قيدُه. */
    public function test_creating_with_an_opening_balance_posts_and_links_its_entry(): void
    {
        $box = $this->box(668);

        $this->assertEqualsWithDelta(668.0, $this->service->balance($box), 0.01);
        $this->assertEqualsWithDelta(668.0, (float) $box->opening_balance, 0.01);
        $this->assertNotNull($box->opening_entry_id);
    }

    /** والصفر لا يُنشئ قيدًا بلا أثر. */
    public function test_a_zero_opening_posts_nothing(): void
    {
        $box = $this->box(0);

        $this->assertNull($box->opening_entry_id);
        $this->assertSame(0, $this->openingEntries($box));
    }

    // ────────── بعد الإنشاء ──────────

    /**
     * ويُدخَل بعد الإنشاء — وهذا سببُ هذه المرحلة كلّها.
     *
     * الحقل كان يظهر عند الإنشاء وحده و`update()` لا يقرأه، فمن نسيه بقي بلا باب.
     */
    public function test_an_opening_balance_can_be_set_after_creation(): void
    {
        $box = $this->box(0);

        $this->service->update($box, ['opening_balance' => 668]);

        $this->assertEqualsWithDelta(668.0, $this->service->balance($box->fresh()), 0.01);
        $this->assertEqualsWithDelta(668.0, (float) $box->fresh()->opening_balance, 0.01);
    }

    /**
     * وتغييرُه يعكس الأصل ويُرحّل مصحَّحًا — لا يُضاف فوقه.
     *
     * بلا معرفة القيد الأصلي كان التعديل يُرحّل قيدًا ثانيًا ويتضاعف الرصيد.
     */
    public function test_changing_it_reverses_and_reposts(): void
    {
        $box = $this->box(668);
        $first = $box->opening_entry_id;

        $this->service->update($box->fresh(), ['opening_balance' => 900]);
        $updated = $box->fresh();

        $this->assertNotSame($first, $updated->opening_entry_id);
        $this->assertTrue(JournalEntry::findOrFail($first)->isReversed());
        $this->assertEqualsWithDelta(900.0, $this->service->balance($updated), 0.01);
    }

    /** وحفظٌ بالقيمة نفسها لا يُرحّل مرّة أخرى. */
    public function test_saving_the_same_amount_posts_nothing_new(): void
    {
        $box = $this->box(668);

        $this->service->update($box->fresh(), ['opening_balance' => 668]);

        $this->assertSame(1, $this->openingEntries($box));
        $this->assertEqualsWithDelta(668.0, $this->service->balance($box->fresh()), 0.01);
    }

    /** وحفظٌ بلا الحقل أصلًا لا يمسّ رصيدًا مُرحّلًا. */
    public function test_saving_without_the_field_keeps_the_balance(): void
    {
        $box = $this->box(668);

        $this->service->update($box->fresh(), ['name' => 'صندوق طه المركزي']);

        $this->assertEqualsWithDelta(668.0, (float) $box->fresh()->opening_balance, 0.01);
        $this->assertEqualsWithDelta(668.0, $this->service->balance($box->fresh()), 0.01);
    }

    /** وتصفيرُه يعكس القيد ولا يترك قيدًا جديدًا. */
    public function test_clearing_it_reverses_without_a_new_entry(): void
    {
        $box = $this->box(668);

        $this->service->update($box->fresh(), ['opening_balance' => 0]);
        $updated = $box->fresh();

        $this->assertNull($updated->opening_entry_id);
        $this->assertEqualsWithDelta(0.0, $this->service->balance($updated), 0.01);
    }

    /** وخزينةٌ بلا حساب محاسبي تُرفض برسالة لا بخطأ. */
    public function test_a_treasury_without_a_ledger_account_is_refused(): void
    {
        $orphan = Treasury::create([
            'code' => 'CB-ORPHAN', 'name' => 'بلا حساب', 'type' => 'cash', 'currency' => 'ILS',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->syncOpeningBalance($orphan, 100);
    }

    // ────────── الشاشة ──────────

    /** والحقل في نموذج التعديل يُرحّل فعلًا. */
    public function test_the_edit_screen_posts_the_opening_balance(): void
    {
        $box = $this->box(0);

        $this->put(route('admin.accounting.cashboxes.update', $box), [
            'code' => $box->code,
            'name' => $box->name,
            'currency' => 'ILS',
            'opening_balance' => 668,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(668.0, $this->service->balance($box->fresh()), 0.01);
    }
}
