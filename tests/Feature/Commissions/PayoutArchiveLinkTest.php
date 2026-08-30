<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Services\CommissionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «عرض السند» في أرشيف الدفعات يفتح السند فعلًا.
 *
 * ## العطب
 *
 * `FinancialVoucher` يستعمل `HasUuid`، فمفتاح مساره **uuid** لا **id**. وكان
 * الرابط يُمرَّر رقمَ السند (`financial_voucher_id`)، فيبحث ربطُ النموذج عن سندٍ
 * uuid‑ه «8» فلا يجده: رابطٌ ظاهرٌ يفتح صفحة «غير موجود».
 *
 * وله وجهٌ ثانٍ أخفى: التحميل المسبق كان `voucher:id,number,status,kind` بلا
 * `uuid`. فحتى لو مُرِّر النموذج، بُني الرابط بمفتاحٍ فارغ. فالإصلاح لا يتمّ
 * بأحدهما وحده — وهذا ما يحرسه الاختبار الأخير.
 */
class PayoutArchiveLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->affiliate = User::factory()->create(['name' => 'سائد شاهين']);
        $this->actingAs($this->admin);
    }

    private function payout(string $notes = 'تسوية شهر آب'): CommissionPayout
    {
        return app(CommissionService::class)->payAmount(
            actor: $this->admin,
            earnerId: $this->affiliate->id,
            earnerType: 'affiliate',
            amount: 250,
            treasuryId: Treasury::active()->firstOrFail()->id,
            counterAccountId: Account::where('code', '5040')->firstOrFail()->id,
            periodStart: '2026-08-01',
            periodEnd: '2026-08-31',
            reference: null,
            notes: $notes,
        );
    }

    private function statement(): TestResponse
    {
        return $this->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate',
        ]));
    }

    // ────────── الرابط ──────────

    /** **الرابط يحمل uuid السند لا رقمه.** */
    public function test_the_link_uses_the_voucher_uuid(): void
    {
        $payout = $this->payout();
        $voucher = $payout->voucher()->firstOrFail();

        $this->statement()->assertOk()->assertSee($voucher->uuid, false);
    }

    /** **ويفتح صفحة السند فعلًا** — وهو ما يشتكي منه المستخدم. */
    public function test_the_voucher_page_opens(): void
    {
        $voucher = $this->payout()->voucher()->firstOrFail();

        $this->get(route('admin.accounting.vouchers.show', [
            'kind' => $voucher->kind, 'voucher' => $voucher,
        ]))->assertOk()->assertSee($voucher->number);
    }

    /**
     * **والتحميل المسبق يجلب uuid.**
     *
     * بغيره يُبنى الرابط بمفتاحٍ فارغ ولو مُرِّر النموذج — فالعطب يعود صامتًا.
     */
    public function test_the_eager_load_includes_the_uuid(): void
    {
        $this->payout();

        $payouts = $this->statement()->assertOk()->viewData('payouts');

        $this->assertNotNull($payouts->first()->voucher->uuid);
    }

    /** ونوع السند يُقرأ من السند لا يُفترض — فلا يُرفض بـ404 على اختلاف النوع. */
    public function test_the_kind_comes_from_the_voucher(): void
    {
        $voucher = $this->payout()->voucher()->firstOrFail();

        $this->assertSame('payment', $voucher->kind);
        $this->statement()->assertOk()->assertSee('/vouchers/'.$voucher->kind.'/'.$voucher->uuid, false);
    }

    // ────────── عمود الملاحظات ──────────

    /** **ملاحظة الدفعة تُقرأ في الجدول بلا فتح السند.** */
    public function test_the_notes_column_shows_the_note(): void
    {
        $this->payout(notes: 'خصم سلفة سابقة');

        $this->statement()->assertOk()
            ->assertSee('ملاحظات')
            ->assertSee('خصم سلفة سابقة');
    }

    /** ودفعةٌ بلا ملاحظة تُعرض بشرطة لا بفراغٍ يُوهم عطبًا. */
    public function test_a_payout_without_a_note_renders_a_dash(): void
    {
        $this->payout(notes: '');

        $this->statement()->assertOk()->assertSee('—');
    }
}
