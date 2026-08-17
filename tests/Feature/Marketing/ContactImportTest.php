<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Marketing\Models\MarketingContact;
use App\Modules\Marketing\Services\ContactImportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * استيراد قائمة أرقام الزبائن.
 *
 * ما تحرسه هذه الاختبارات ليس نجاح الاستيراد بل **ألّا يُرسَل ما لا يجوز**:
 * ألّا يدخل الرقم مرّتين بصيغتين، وألّا يُنشئ الاستيراد موافقةً لم يمنحها
 * أحد، وألّا تُعيد إعادةُ الاستيراد من انسحب أو حجبنا إلى قائمة المُراسَلين.
 *
 * وكلّها أخطاءٌ لا تظهر في شاشة — تظهر حين يُحظر الرقم وتضيع القائمة كلّها.
 */
class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function service(): ContactImportService
    {
        return app(ContactImportService::class);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function import(array $rows, string $consent = MarketingContact::CONSENT_IMPLIED): array
    {
        return $this->service()->import($rows, 'test.csv', $consent, 'زبائن واتساب', null);
    }

    // ────────── التطبيع ──────────

    /**
     * الرقم نفسه بأربع صيغ يدخل مرّة واحدة.
     *
     * وهذا هو الحارس الأهمّ: أربع رسائل إلى شخصٍ واحد أسرع طريقٍ إلى الحجب،
     * والحجبُ يُسقط درجة جودة الرقم ثم يُحظره.
     */
    public function test_one_number_in_four_formats_becomes_one_contact(): void
    {
        $summary = $this->import([
            ['phone' => '0599123456'],
            ['phone' => '+970599123456'],
            ['phone' => '00970599123456'],
            ['phone' => '970 599 123 456'],
        ]);

        $this->assertSame(1, MarketingContact::count());
        $this->assertSame(3, $summary['duplicates']);
        $this->assertSame('970599123456', MarketingContact::first()->phone);
    }

    /** وما ليس رقمًا يُرفض ويُعرَض مثالٌ منه. */
    public function test_non_numbers_are_refused_with_a_sample(): void
    {
        $summary = $this->import([
            ['phone' => '0599123456'],
            ['phone' => '2026-08-18'],      // عمود تاريخ في خانة الهاتف
            ['phone' => '40.00'],
            ['phone' => ''],
        ]);

        $this->assertSame(1, MarketingContact::count());
        $this->assertSame(3, $summary['invalid']);
        // العيّنة تكشف أن العمود خاطئ لا الصفّ.
        $this->assertContains('2026-08-18', $summary['samples']);
    }

    // ────────── الموافقة ──────────

    /** الاستيراد بحالة «غير معروفة» يُنتج جهاتٍ لا تُراسَل. */
    public function test_unknown_consent_produces_unsendable_contacts(): void
    {
        $this->import([['phone' => '0599123456']], MarketingContact::CONSENT_UNKNOWN);

        $contact = MarketingContact::firstOrFail();

        $this->assertFalse($contact->isSendable());
        $this->assertSame(0, MarketingContact::sendable()->count());
        // ولا يُخترَع لها تاريخ موافقة.
        $this->assertNull($contact->consent_at);
    }

    /** والموافقة الضمنية تجعلها قابلة للمراسلة، مع حفظ أساسها نصًّا. */
    public function test_implied_consent_records_its_basis(): void
    {
        $this->import([['phone' => '0599123456']]);

        $contact = MarketingContact::firstOrFail();

        $this->assertTrue($contact->isSendable());
        $this->assertSame('زبائن واتساب', $contact->consent_basis);
        $this->assertNotNull($contact->consent_at);
    }

    /**
     * ومن انسحب لا تُعيده إعادةُ الاستيراد.
     *
     * ملفٌ يُرفع ثانيةً بعد شهر يحمل الرقم نفسه؛ ولو دهس حالته لعادت الرسائل
     * إلى من طلب التوقّف — وهو أسوأ ما يمكن أن يفعله نظام تسويق.
     */
    public function test_re_importing_never_revives_an_opted_out_contact(): void
    {
        $this->import([['phone' => '0599123456']]);

        MarketingContact::first()->update(['consent_state' => MarketingContact::CONSENT_OPTED_OUT]);

        $this->import([['phone' => '0599123456', 'name' => 'اسم جديد']]);

        $contact = MarketingContact::firstOrFail();

        $this->assertSame(MarketingContact::CONSENT_OPTED_OUT, $contact->consent_state);
        $this->assertFalse($contact->isSendable());
        // والبيانات الوصفية تُحدَّث رغم ذلك.
        $this->assertSame('اسم جديد', $contact->name);
    }

    /** ومن حجبنا لا يُراسَل ولو كانت موافقته قائمة. */
    public function test_a_blocked_contact_is_never_sendable(): void
    {
        $this->import([['phone' => '0599123456']]);

        MarketingContact::first()->update(['blocked_at' => now()]);

        $this->assertSame(0, MarketingContact::sendable()->count());
    }

    // ────────── المطابقة ──────────

    /** الرقم الذي يخصّ عميلًا قائمًا يُربَط به. */
    public function test_a_number_belonging_to_a_customer_is_linked(): void
    {
        $customer = Customer::create([
            'branch_id' => Branch::default()->id,
            'name' => 'زبون',
            'primary_phone' => '0599123456',
        ]);

        $summary = $this->import([['phone' => '+970599123456']]);

        $this->assertSame(1, $summary['matched']);
        $this->assertSame($customer->id, MarketingContact::firstOrFail()->customer_id);
    }

    /** ومن ليس عميلًا يبقى جهة اتصال بلا حساب محاسبي. */
    public function test_a_stranger_stays_a_contact_without_a_ledger_account(): void
    {
        $before = Customer::count();

        $this->import([['phone' => '0599999999']]);

        $this->assertNull(MarketingContact::firstOrFail()->customer_id);
        // ولا عميل جديد — ولا حساب في دليل الحسابات معه.
        $this->assertSame($before, Customer::count());
    }

    // ────────── الشاشة ──────────

    /** الصفحة مغلقة على من لا يملك الاطّلاع. */
    public function test_the_page_is_gated_by_permission(): void
    {
        $this->actingAs($this->admin())->get(route('admin.marketing.contacts.index'))->assertOk();

        $outsider = User::factory()->create(['branch_id' => Branch::default()->id]);
        $outsider->assignRole('warehouse');

        $this->actingAs($outsider)->get(route('admin.marketing.contacts.index'))->assertForbidden();
    }

    /** ورفعُ ملفٍ حقيقي يمرّ من الترويسة إلى الاستيراد. */
    public function test_uploading_a_csv_imports_the_column_mapped_to_phone(): void
    {
        $csv = "الاسم,الهاتف,المدينة\nسعاد,0599123456,الخليل\nأحمد,0598111222,نابلس\n";

        $this->actingAs($this->admin())->post(route('admin.marketing.contacts.import'), [
            'file' => UploadedFile::fake()->createWithContent('list.csv', $csv),
            'phone_column' => 2,
            'name_column' => 1,
            'city_column' => 3,
            'has_header' => 1,
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
            'consent_basis' => 'زبائن سابقون',
        ])->assertRedirect();

        $this->assertSame(2, MarketingContact::count());

        $contact = MarketingContact::where('phone', '970599123456')->firstOrFail();
        $this->assertSame('سعاد', $contact->name);
        $this->assertSame('الخليل', $contact->extra['city']);
    }

    /** والترويسة لا تُستورَد رقمًا. */
    public function test_the_header_row_is_not_imported(): void
    {
        $csv = "phone\n0599123456\n";

        $this->actingAs($this->admin())->post(route('admin.marketing.contacts.import'), [
            'file' => UploadedFile::fake()->createWithContent('list.csv', $csv),
            'phone_column' => 1,
            'has_header' => 1,
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ])->assertRedirect();

        $this->assertSame(1, MarketingContact::count());
        $this->assertSame('970599123456', MarketingContact::firstOrFail()->phone);
    }

    /** ووسمُ «لا تراسله» يُخرجه من القائمة فورًا. */
    public function test_opting_out_removes_a_contact_from_the_sendable_list(): void
    {
        $this->import([['phone' => '0599123456']]);
        $contact = MarketingContact::firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.marketing.contacts.opt_out', $contact))
            ->assertRedirect();

        $this->assertSame(MarketingContact::CONSENT_OPTED_OUT, $contact->refresh()->consent_state);
        $this->assertSame(0, MarketingContact::sendable()->count());
    }
}
