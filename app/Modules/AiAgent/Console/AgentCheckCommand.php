<?php

namespace App\Modules\AiAgent\Console;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Catalog\Models\Product;
use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Console\Command;

/**
 * فحص جاهزية وكيل المبيعات — وإنشاء قناة واتساب.
 *
 * الوكيل يعتمد على ستّة أشياء مستقلّة (مفتاح النموذج، رقم واتساب، صفّ القناة
 * في قاعدة البيانات، المفتاحان العام والقنويّ، عامل الطابور، معرفةٌ جاهزة).
 * وسقوط أيٍّ منها يُنتج **العَرَض نفسه**: لا ردّ. فتُفحص مجتمعةً في سطرٍ واحد
 * بدل أن يُخمَّن السبب واحدًا واحدًا.
 *
 * و**إنشاء صفّ القناة** هنا لأنه لم يكن له طريق أصلًا: الاستقبال يبحث عن قناةٍ
 * بمعرّف الرقم، ولا يجدها فيُسقط رسالة الزبون صامتًا — فلا يعمل الوكيل ولا
 * يقول أحدٌ لماذا.
 */
class AgentCheckCommand extends Command
{
    protected $signature = 'ai-agent:check
                            {--create-channel : إنشاء قناة واتساب من إعدادات البيئة إن لم تكن موجودة}';

    protected $description = 'فحص جاهزية وكيل المبيعات، وإنشاء قناة واتساب عند الحاجة';

    public function handle(): int
    {
        $this->line('');

        $phoneNumberId = (string) config('messaging.whatsapp.phone_number_id');

        if ($this->option('create-channel')) {
            $this->createChannel($phoneNumberId);
        }

        $channel = $phoneNumberId === '' ? null : MessagingChannel::where('provider', 'whatsapp')
            ->where('external_id', $phoneNumberId)->first();

        $ready = ProductKnowledge::where('is_ready', true)->count();
        $queue = (string) config('queue.default');

        // العمود الأول **لاتينيّ دائمًا**: طرفيّات الخوادم كثيرًا ما تعجز عن
        // عرض العربية، وأمرُ تشخيصٍ لا يُقرأ لا يُشخّص شيئًا. فيقود كلَّ سطرٍ
        // مفتاحُ البيئة أو الأمر المطلوب، والشرح العربيّ يتبعه.
        $rows = [
            $this->row('ANTHROPIC_API_KEY', filled(config('ai_agent.api_key')),
                'set', 'MISSING — كل دورة تفشل وتُحوَّل'),

            $this->row('AI_AGENT_ENABLED', (bool) config('ai_agent.enabled'),
                'true', 'false — الاستقبال يعمل والردّ لا'),

            $this->row('WHATSAPP_PHONE_NUMBER_ID', $phoneNumberId !== '',
                $phoneNumberId, 'MISSING — بدونه لا قناة ولا استقبال'),

            $this->row('WHATSAPP_TOKEN', filled(config('messaging.whatsapp.token')),
                'set', 'MISSING — لا إرسال'),

            // المسار `messaging.whatsapp.*` لا `messaging.webhooks.*`: هو ما يقرؤه
            // متحكّم الـwebhook فعلًا. وفحصٌ يقرأ مفتاحًا غير موجود يقول MISSING
            // إلى الأبد — فيُطارَد عطلٌ ليس هناك.
            $this->row('WHATSAPP_VERIFY_TOKEN', filled(config('messaging.whatsapp.verify_token')),
                'set', 'MISSING — ميتا لن تُفعّل الـwebhook'),

            $this->row('WHATSAPP_APP_SECRET', filled(config('messaging.whatsapp.app_secret')),
                'set', 'MISSING — الرسائل الواردة تُرفض'),

            $this->row('MESSAGING_WHATSAPP (driver)', config('messaging.channels.whatsapp') === 'whatsapp_cloud',
                'whatsapp_cloud', (string) config('messaging.channels.whatsapp').' — يجب أن يكون whatsapp_cloud'),

            $this->row('messaging_channels row', $channel !== null,
                $channel?->name ?? '', 'MISSING — php artisan ai-agent:check --create-channel'),

            $this->row('channel ai_enabled', (bool) $channel?->ai_enabled,
                'true', 'false — شغّله من: التسويق ← الصندوق الموحّد'),

            // `sync` يعني أن الردّ يجري داخل الـwebhook: يتجاوز المهلة وتُعيد
            // ميتا الإرسال، فيردّ الوكيل مرّتين.
            $this->row('QUEUE_CONNECTION', $queue !== 'sync',
                $queue, 'sync — يلزم: php artisan queue:work'),

            $this->row('ready products', $ready > 0,
                $ready.' / '.Product::count(), '0 — الوكيل سيحوّل كل سؤال'),
        ];

        $this->table(['CHECK', 'OK', 'RESULT'], $rows);

        $this->printWebhookSetup();

        $failed = collect($rows)->filter(fn (array $r) => $r[1] === '✗')->count();

        $this->line('');

        if ($failed === 0) {
            $this->info('ALL OK — أرسل رسالة إلى رقم المتجر وراقب الصندوق الموحّد.');

            return self::SUCCESS;
        }

        $this->warn("{$failed} check(s) need attention — راجع عمود RESULT أعلاه.");
        $this->line('docs/AI_AGENT_OPERATIONS.md');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function row(string $label, bool $ok, string $good, string $bad): array
    {
        return [$label, $ok ? '✓' : '✗', $ok ? $good : $bad];
    }

    /**
     * ما يُلصَق في لوحة ميتا.
     *
     * الجدول يقول ما ينقص في `.env`، ولا يقول شيئًا عن **الطرف الآخر**: عنوانٌ
     * يُكتب في ميتا وحقلٌ يُفعَّل هناك. وتخمينُ العنوان أو نسيانُ الحقل يُنتج
     * الصمت نفسه — الرسائل لا تصل أصلًا فيُفتَّش عن العطل في الوكيل.
     *
     * ولا يُطبع الرمز نفسه: هو في `.env`، وطباعتُه في طرفيّةٍ تُصوَّر وتُرسَل
     * تُخرجه من هناك.
     */
    private function printWebhookSetup(): void
    {
        $this->line('');
        $this->line('WEBHOOK (paste into Meta → WhatsApp → Configuration):');
        $this->line('  Callback URL:  '.rtrim((string) config('app.url'), '/').'/api/webhooks/whatsapp');
        $this->line('  Verify token:  = WHATSAPP_VERIFY_TOKEN (من .env — لا يُطبع هنا)');
        $this->line('  Webhook fields: messages  ← فعّله، وإلّا لا تصل رسالة واحدة');
    }

    /**
     * إنشاء صفّ القناة من إعدادات البيئة.
     *
     * `external_id` هو **معرّف الرقم لدى ميتا** لا الرقم نفسه: به يربط
     * الـwebhook الرسالةَ الواردة بقناتها، ومطابقتُه بالرقم البشريّ تُسقط كل
     * رسالة.
     */
    private function createChannel(string $phoneNumberId): void
    {
        if ($phoneNumberId === '') {
            $this->error('WHATSAPP_PHONE_NUMBER_ID is MISSING — لا يمكن إنشاء القناة بدونه.');

            return;
        }

        $existing = MessagingChannel::where('provider', 'whatsapp')
            ->where('external_id', $phoneNumberId)->first();

        if ($existing) {
            $this->info("channel exists: {$existing->name}");

            return;
        }

        $channel = MessagingChannel::create([
            'provider' => 'whatsapp',
            'name' => 'واتساب المتجر',
            'external_id' => $phoneNumberId,
            'is_active' => true,
            // مطفأ عند الإنشاء عمدًا: إنشاء القناة إجراءٌ تقنيّ، وتشغيل الوكيل
            // قرارٌ إداريّ يُتَّخذ من الصندوق بعد قراءة ما سيقوله.
            'ai_enabled' => false,
        ]);

        $this->info("channel created: {$channel->name} (ai_enabled = false)");
        $this->line('  شغّلها من: التسويق ← الصندوق الموحّد.');
    }
}
