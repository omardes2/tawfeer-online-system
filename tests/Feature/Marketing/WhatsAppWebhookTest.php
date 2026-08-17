<?php

namespace Tests\Feature\Marketing;

use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\MarketingContact;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * webhook حالات واتساب.
 *
 * نقطةٌ عامّة تُحدِّث بياناتنا، فأول ما تحرسه الاختبارات **أن لا أحد غير المنصّة
 * يستطيع الكتابة فيها**: بلا تحقّق توقيع يستطيع أيُّ أحدٍ أن يُخبرها أن رسائلنا
 * سُلّمت — أو أن يُغرقها بحالاتٍ ملفّقة تُفسد أرقامنا وتوقف حملتنا.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('messaging.whatsapp.app_secret', self::SECRET);
        config()->set('messaging.whatsapp.verify_token', 'verify-me');
    }

    /** @param array<string, mixed> $payload */
    private function hook(array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);
        $headers = $secret === null
            ? []
            : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], $this->transform($headers), $body);
    }

    /** @param array<string, string> $headers */
    private function transform(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $key => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($key))] = $value;
        }

        return $server;
    }

    /** @param array<string, mixed> $status */
    private function payload(array $status): array
    {
        return ['entry' => [['changes' => [['value' => ['statuses' => [$status]]]]]]];
    }

    private function message(string $reference, string $phone = '970599123456'): CampaignMessage
    {
        $campaign = Campaign::create([
            'name' => 'حملة', 'use_case' => 'win_back', 'channel' => 'whatsapp',
            'status' => 'active', 'trigger_type' => 'manual',
        ]);

        return CampaignMessage::create([
            'campaign_id' => $campaign->id,
            'channel' => 'whatsapp',
            'recipient' => $phone,
            'status' => 'sent',
            'provider_reference' => $reference,
            'idempotency_key' => 'k-'.$reference,
        ]);
    }

    // ────────── الحارس ──────────

    /** بلا توقيع لا يُقبل شيء. */
    public function test_an_unsigned_request_is_rejected(): void
    {
        $message = $this->message('wamid.1');

        $this->hook($this->payload(['id' => 'wamid.1', 'status' => 'delivered']), null)
            ->assertForbidden();

        $this->assertSame('sent', $message->refresh()->status);
    }

    /** وبتوقيعٍ خاطئ كذلك. */
    public function test_a_wrongly_signed_request_is_rejected(): void
    {
        $this->message('wamid.1');

        $this->hook($this->payload(['id' => 'wamid.1', 'status' => 'delivered']), 'wrong-secret')
            ->assertForbidden();
    }

    /** والتحقّق الأولي يعيد التحدّي لمن يعرف الرمز وحده. */
    public function test_verification_returns_the_challenge_only_for_the_right_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_verify_token=verify-me&hub_challenge=1234')
            ->assertOk()
            ->assertSee('1234');

        $this->get('/api/webhooks/whatsapp?hub_verify_token=wrong&hub_challenge=1234')
            ->assertForbidden();
    }

    // ────────── الحالات ──────────

    /** الحالة الموقّعة تُحدِّث الرسالة. */
    public function test_a_signed_status_updates_the_message(): void
    {
        $message = $this->message('wamid.1');

        $this->hook($this->payload(['id' => 'wamid.1', 'status' => 'delivered']))->assertOk();

        $this->assertSame('delivered', $message->refresh()->status);
    }

    /**
     * والحالة لا ترجع للوراء.
     *
     * تصل الحالات خارج ترتيبها أحيانًا، وكتابةُ الأحدث فوق الأقدم بلا ترتيب
     * تُنزل رسالةً مقروءة إلى «مُرسَلة».
     */
    public function test_a_later_status_never_regresses(): void
    {
        $message = $this->message('wamid.1');

        $this->hook($this->payload(['id' => 'wamid.1', 'status' => 'read']))->assertOk();
        $this->hook($this->payload(['id' => 'wamid.1', 'status' => 'delivered']))->assertOk();

        $this->assertSame('read', $message->refresh()->status);
    }

    // ────────── الحجب ──────────

    /** فشلٌ دائم يُخرج الرقم من القائمة نهائيًّا. */
    public function test_a_permanent_failure_blocks_the_contact(): void
    {
        $contact = MarketingContact::create([
            'phone' => '970599123456',
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ]);

        $this->message('wamid.1');

        $this->hook($this->payload([
            'id' => 'wamid.1',
            'status' => 'failed',
            'recipient_id' => '970599123456',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]))->assertOk();

        $this->assertNotNull($contact->refresh()->blocked_at);
        $this->assertSame(0, MarketingContact::sendable()->count());
    }

    /**
     * والفشل العابر لا يُوسَم.
     *
     * «تجاوزتَ الحدّ اليومي» عيبٌ فينا لا في الرقم، ووسمُه كان سيحرق القائمة
     * بأيدينا في أول تشغيلةٍ متعجّلة.
     */
    public function test_a_transient_failure_does_not_block_the_contact(): void
    {
        $contact = MarketingContact::create([
            'phone' => '970599123456',
            'consent_state' => MarketingContact::CONSENT_IMPLIED,
        ]);

        $this->message('wamid.1');

        $this->hook($this->payload([
            'id' => 'wamid.1',
            'status' => 'failed',
            'recipient_id' => '970599123456',
            'errors' => [['code' => 130429, 'title' => 'Rate limit hit']],
        ]))->assertOk();

        $this->assertNull($contact->refresh()->blocked_at);
        $this->assertSame(1, MarketingContact::sendable()->count());
    }

    /** وحمولةٌ بلا حالات تمرّ بهدوء — الإشعارات ليست كلّها حالات. */
    public function test_a_payload_without_statuses_is_harmless(): void
    {
        $this->hook(['entry' => [['changes' => [['value' => ['messages' => [['from' => '970599123456']]]]]]]])
            ->assertOk();
    }
}
