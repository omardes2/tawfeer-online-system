<?php

namespace Tests\Feature\Marketing;

use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\CampaignTemplate;
use App\Modules\Marketing\Models\MarketingContact;
use App\Modules\Marketing\Services\ContactBroadcastService;
use App\Support\Integrations\Messaging\FakeMessagingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * الإرسال المجمّع عبر واتساب.
 *
 * ما تحرسه هذه الاختبارات ليس أن الرسالة تصل، بل **أن الرقم لا يُحرق**: ألّا
 * يُراسَل من لا يجوز، ولا يُتجاوز الحدّ اليومي، ولا تتكرّر الرسالة، ولا يستمرّ
 * الإرسال على فشلٍ متراكم.
 *
 * وكلّها أخطاءٌ لا تظهر في شاشة — تظهر يوم يُحظر الرقم وتضيع القائمة كلّها.
 */
class WhatsAppBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('messaging.channels.whatsapp', 'fake');
        FakeMessagingProvider::reset();
    }

    private function template(?string $providerTemplate = 'winback_ar'): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name' => 'استرجاع',
            'channel' => 'whatsapp',
            'body_ar' => 'مرحبًا :customer_name',
            'provider_template' => $providerTemplate,
            'provider_language' => 'ar',
            'provider_params' => ['customer_name'],
            'is_active' => true,
        ]);
    }

    /** @return Collection<int, MarketingContact> */
    private function contacts(int $count, array $overrides = [])
    {
        return collect(range(1, $count))->map(fn (int $i) => MarketingContact::create(array_merge([
            'phone' => '97059900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => 'زبون '.$i,
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ], $overrides)));
    }

    private function broadcast(): ContactBroadcastService
    {
        return app(ContactBroadcastService::class);
    }

    // ────────── الحرّاس ──────────

    /** من لا موافقة له لا يُراسَل. */
    public function test_contacts_without_consent_are_never_messaged(): void
    {
        $this->contacts(3, ['consent_state' => MarketingContact::CONSENT_UNKNOWN]);

        $summary = $this->broadcast()->run($this->template());

        $this->assertSame(0, $summary['sent']);
        $this->assertSame([], FakeMessagingProvider::$sent);
    }

    /** ومن انسحب لا يُراسَل. */
    public function test_opted_out_contacts_are_never_messaged(): void
    {
        $this->contacts(3, ['consent_state' => MarketingContact::CONSENT_OPTED_OUT]);

        $this->assertSame(0, $this->broadcast()->run($this->template())['sent']);
    }

    /** ومن حجبنا لا يُراسَل ولو كانت موافقته قائمة. */
    public function test_blocked_contacts_are_never_messaged(): void
    {
        $this->contacts(3, [
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
            'blocked_at' => now(),
        ]);

        $this->assertSame(0, $this->broadcast()->run($this->template())['sent']);
    }

    /**
     * والحدّ اليومي لا يُتجاوز.
     *
     * وهو طبقة الرقم لدى المنصّة لا رغبتنا: تجاوزه يُرفَض، وتكرار الرفض يُسقط
     * درجة الجودة ثم يُحظر الرقم.
     */
    public function test_the_daily_limit_is_never_exceeded(): void
    {
        config()->set('messaging.bulk.daily_limit', 5);
        config()->set('messaging.bulk.batch', 50);

        $this->contacts(20);

        $summary = $this->broadcast()->run($this->template());

        $this->assertSame(5, $summary['sent']);
        $this->assertCount(5, FakeMessagingProvider::$sent);
        $this->assertSame(0, $summary['remaining_today']);
    }

    /** ويُحسب المُرسَل عبر الحملات كلّها لا حملةً حملة — المنصّة تعدّ الرقم. */
    public function test_the_daily_limit_counts_across_campaigns(): void
    {
        config()->set('messaging.bulk.daily_limit', 5);

        $this->contacts(20);
        $this->broadcast()->run($this->template());

        // قالبٌ ثانٍ في اليوم نفسه لا يجد متّسعًا.
        $second = $this->broadcast()->run($this->template('other_ar'));

        $this->assertSame(0, $second['sent']);
        $this->assertStringContainsString('الحدّ اليومي', $second['reason']);
    }

    /** ولا تتكرّر الرسالة على من رُوسل في الحملة نفسها. */
    public function test_a_contact_is_never_messaged_twice_in_one_campaign(): void
    {
        $this->contacts(3);
        $template = $this->template();

        $this->broadcast()->run($template);
        $again = $this->broadcast()->run($template);

        $this->assertSame(3, CampaignMessage::count());
        $this->assertSame(0, $again['sent']);
        $this->assertCount(3, FakeMessagingProvider::$sent);
    }

    /** وقالبٌ بلا اسمٍ معتمَد لا يُرسَل أصلًا. */
    public function test_a_template_without_an_approved_name_sends_nothing(): void
    {
        $this->contacts(3);

        $summary = $this->broadcast()->run($this->template(null));

        $this->assertSame(0, $summary['sent']);
        $this->assertStringContainsString('اسمٍ معتمَد', $summary['reason']);
    }

    /** والقالب يُرسَل باسمه ولغته ومتغيّراته بالترتيب. */
    public function test_the_approved_template_is_sent_with_ordered_params(): void
    {
        MarketingContact::create([
            'phone' => '970599123456',
            'name' => 'سعاد',
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ]);

        $this->broadcast()->run($this->template());

        $sent = FakeMessagingProvider::$sent[0] ?? null;

        $this->assertNotNull($sent);
        $this->assertSame('winback_ar', $sent['meta']['template']);
        $this->assertSame('ar', $sent['meta']['language']);
        $this->assertSame(['سعاد'], $sent['meta']['params']);
    }

    /** ووقت الإرسال يُسجَّل على جهة الاتصال. */
    public function test_the_contact_records_when_it_was_last_contacted(): void
    {
        $this->contacts(1);

        $this->broadcast()->run($this->template());

        $this->assertNotNull(MarketingContact::firstOrFail()->last_contacted_at);
    }

    /** وترتيب الإرسال يبدأ بمن اشترى فعلًا. */
    public function test_customers_are_messaged_before_strangers(): void
    {
        config()->set('messaging.bulk.batch', 1);

        MarketingContact::create([
            'phone' => '970599000001', 'name' => 'غريب',
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ]);

        $customer = Customer::create([
            'branch_id' => Branch::default()->id,
            'name' => 'زبون', 'primary_phone' => '0599000002',
        ]);

        MarketingContact::create([
            'phone' => '970599000002', 'name' => 'زبون',
            'customer_id' => $customer->id,
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ]);

        $this->broadcast()->run($this->template());

        $this->assertSame('970599000002', FakeMessagingProvider::$sent[0]['to']);
    }
}
