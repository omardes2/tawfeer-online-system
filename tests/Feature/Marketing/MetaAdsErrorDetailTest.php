<?php

namespace Tests\Feature\Marketing;

use App\Support\Integrations\AdPlatform\MetaAdsProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * خطأ Meta يصل كاملًا — بالرموز التي تقول السبب.
 *
 * ## لماذا الرسالة وحدها لا تكفي
 *
 * «Cannot call API for app X on behalf of user Y» تصدر عن سحب صلاحية، وعن رمزٍ
 * منتهٍ، وعن تطبيقٍ خارج الإنتاج — ثلاثة أسباب ونصٌّ واحد. ومن يقرأها وحدها
 * يُصلح ما ليس معطوبًا: يُعيد المستخدم مديرًا على الحساب ويظنّ الأمر انتهى،
 * والمفتاح ميّتٌ لا يُحييه ذلك.
 *
 * والرمز الفرعيّ يفصل بينها، فيُطبع معها.
 */
class MetaAdsErrorDetailTest extends TestCase
{
    private function fetch(): void
    {
        config()->set('ads.meta.token', 'x');
        config()->set('ads.meta.account_id', '123');

        app(MetaAdsProvider::class)->dailyInsights(
            Carbon::parse('2026-09-05'),
            Carbon::parse('2026-09-05'),
        );
    }

    /** **الرمز والرمز الفرعيّ والنوع في الرسالة** — لا الوصف وحده. */
    public function test_the_error_carries_meta_codes(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Cannot call API for app 1 on behalf of user 2',
                'type' => 'OAuthException',
                'code' => 200,
                'error_subcode' => 1349194,
            ],
        ], 403)]);

        try {
            $this->fetch();
            $this->fail('كان يجب أن يُرمى استثناء.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot call API', $e->getMessage());
            $this->assertStringContainsString('code=200', $e->getMessage());
            $this->assertStringContainsString('subcode=1349194', $e->getMessage());
            $this->assertStringContainsString('OAuthException', $e->getMessage());
        }
    }

    /** ورسالة Meta الموجَّهة للإنسان تُعرض حين تُرسلها. */
    public function test_the_user_facing_message_is_shown_when_present(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Unsupported get request.',
                'error_user_msg' => 'ليس لديك صلاحية على هذا الحساب الإعلاني.',
                'code' => 100,
            ],
        ], 400)]);

        try {
            $this->fetch();
            $this->fail('كان يجب أن يُرمى استثناء.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليس لديك صلاحية', $e->getMessage());
        }
    }

    /** وردٌّ بلا جسم خطأ يُعطي رمز الحالة لا فراغًا. */
    public function test_a_bodyless_failure_falls_back_to_the_status(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        try {
            $this->fetch();
            $this->fail('كان يجب أن يُرمى استثناء.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }
}
