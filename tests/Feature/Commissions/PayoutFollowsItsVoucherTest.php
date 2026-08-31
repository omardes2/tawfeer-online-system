<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Services\CommissionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدفعة تتبع سندها — لا نسخةً تُكتب مرّةً وتُنسى.
 *
 * ## العطب
 *
 * `commission_payouts.total` نسخةٌ من مبلغ سند الصرف تُكتب لحظة الصرف. وتعديلُ
 * السند بعدها مسارٌ مشروع — يُعكس القيد ويُرحَّل قيدٌ مُصحّح — لكنّ النسخة كانت
 * تبقى على القيمة القديمة.
 *
 * فيقول الدفتر ٨٬٣٧٧ ويقول أرشيف الدفعات ٧٬٣٣٧، ويُحسب «الرصيد المتبقّي» على
 * الرقم القديم — فيظهر للمسوّق مستحقٌّ صُرف فعلًا، ويُصرف عليه مرّةً ثانية.
 *
 * **ولا يظهر ذلك خطأً في أي شاشة**: رقمان كلاهما «صحيح» في مكانه.
 *
 * ## الحلّ من طرفين
 *
 * القراءة تُشتقّ من السند (فتُصحَّح دفعاتٌ عُدّلت قبل اليوم بلا إصلاح بيانات)،
 * والعمود يُزامَن بحدث `VoucherRevised` (فلا يبقى كاذبًا في قاعدة البيانات
 * لتصديرٍ أو استعلامٍ مباشر).
 */
class PayoutFollowsItsVoucherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $earner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $this->earner = User::factory()->create(['name' => 'مسوّق']);
    }

    private function pay(float $amount): CommissionPayout
    {
        return app(CommissionService::class)->payAmount(
            $this->admin,
            $this->earner->id,
            'affiliate',
            $amount,
            Treasury::where('code', 'CB-MAIN')->firstOrFail()->id,
            Account::where('code', '5040')->firstOrFail()->id,
            '2026-08-01',
            '2026-08-31',
        );
    }

    private function editTo(CommissionPayout $payout, float $amount): void
    {
        app(VoucherService::class)->repost(
            $payout->voucher()->first(),
            ['amount' => $amount],
            $this->admin,
        );
    }

    // ────────── جوهر العطب ──────────

    /** **تعديل السند يُحدّث مبلغ الدفعة المحفوظ.** */
    public function test_editing_the_voucher_updates_the_stored_total(): void
    {
        $payout = $this->pay(7337);

        $this->editTo($payout, 8377);

        $this->assertSame('8377.00', $payout->fresh()->total);
    }

    /** والقراءة تُعطي مبلغ السند ولو بقي العمود قديمًا. */
    public function test_the_read_follows_the_voucher_even_if_the_column_is_stale(): void
    {
        $payout = $this->pay(7337);
        $this->editTo($payout, 8377);

        // محاكاة صفٍّ عُدّل قبل وجود المزامنة: العمود قديم والسند صحيح.
        CommissionPayout::where('id', $payout->id)->update(['total' => 7337]);

        $this->assertSame(8377.0, CommissionPayout::with('voucher')->find($payout->id)->settledAmount());
    }

    /** **والرصيد المتبقّي يُحسب على مبلغ السند** — لا على القديم. */
    public function test_the_outstanding_balance_uses_the_voucher_amount(): void
    {
        $payout = $this->pay(7337);
        $before = app(CommissionService::class)->balance($this->earner->id, 'affiliate');

        $this->editTo($payout, 8377);
        $after = app(CommissionService::class)->balance($this->earner->id, 'affiliate');

        $this->assertSame(7337.0, $before['paid']);
        $this->assertSame(8377.0, $after['paid']);
        $this->assertSame(round($before['outstanding'] - 1040, 2), $after['outstanding']);
    }

    /** وكذلك إجمالي المستحقّ على الشركة في لوحة التحكّم. */
    public function test_the_company_wide_outstanding_uses_the_voucher_amount(): void
    {
        $payout = $this->pay(1000);
        $before = app(CommissionService::class)->outstandingTotal();

        $this->editTo($payout, 1500);

        $this->assertSame(round($before - 500, 2), app(CommissionService::class)->outstandingTotal());
    }

    /** والأرشيف في الشاشة يعرض الرقم الجديد لا القديم. */
    public function test_the_archive_shows_the_new_amount(): void
    {
        $payout = $this->pay(7337);
        $this->editTo($payout, 8377);

        $this->get(route('admin.commissions.statement', [
            'earnerId' => $this->earner->id, 'earner_type' => 'affiliate',
        ]))->assertOk()->assertSee('8,377.00')->assertDontSee('7,337.00');
    }

    // ────────── ما لا يتغيّر ──────────

    /** والخزينة تتبع السند كذلك — من صُحّح بنكُه يُقرأ بنكُه الجديد. */
    public function test_the_treasury_follows_the_voucher(): void
    {
        $payout = $this->pay(500);
        $bank = Treasury::where('code', 'BNK-MAIN')->firstOrFail();

        app(VoucherService::class)->repost(
            $payout->voucher()->first(),
            ['amount' => 500, 'treasury_id' => $bank->id],
            $this->admin,
        );

        $this->assertSame($bank->id, $payout->fresh()->treasury_id);
    }

    /** ودفعةٌ بلا سند — من النظام السابق — يبقى عمودها هو المصدر. */
    public function test_a_payout_without_a_voucher_keeps_its_column(): void
    {
        $legacy = CommissionPayout::create([
            'earner_id' => $this->earner->id,
            'earner_type' => 'affiliate',
            'total' => 250,
            'status' => 'paid',
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame(250.0, $legacy->settledAmount());
        $this->assertSame(250.0, app(CommissionService::class)->balance($this->earner->id, 'affiliate')['paid']);
    }

    /** **وسندٌ معكوس لا يُحتسب مصروفًا** — ماله عاد. */
    public function test_a_reversed_voucher_is_not_counted_as_paid(): void
    {
        $payout = $this->pay(1000);

        app(VoucherService::class)->reverse($payout->voucher()->first());

        $this->assertSame(0.0, app(CommissionService::class)->balance($this->earner->id, 'affiliate')['paid']);
    }
}
