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

        $rows = [
            $this->row('مفتاح النموذج (ANTHROPIC_API_KEY)', filled(config('ai_agent.api_key')),
                'مضبوط', 'غير مضبوط — كل دورة تفشل وتُحوَّل'),

            $this->row('المفتاح العام (AI_AGENT_ENABLED)', (bool) config('ai_agent.enabled'),
                'مشغّل', 'مطفأ — الاستقبال يعمل والردّ لا'),

            $this->row('رقم واتساب (WHATSAPP_PHONE_NUMBER_ID)', $phoneNumberId !== '',
                $phoneNumberId, 'غير مضبوط'),

            $this->row('رمز واتساب (WHATSAPP_TOKEN)', filled(config('messaging.whatsapp.token')),
                'مضبوط', 'غير مضبوط — لا إرسال'),

            $this->row('تحقّق الـwebhook (WHATSAPP_VERIFY_TOKEN)', filled(config('messaging.webhooks.verify_token')),
                'مضبوط', 'غير مضبوط — ميتا لن تُفعّل الـwebhook'),

            $this->row('توقيع الـwebhook (WHATSAPP_APP_SECRET)', filled(config('messaging.webhooks.app_secret')),
                'مضبوط', 'غير مضبوط — الرسائل الواردة تُرفض'),

            $this->row('قناة واتساب في النظام', $channel !== null,
                $channel?->name ?? '', 'غير موجودة — شغّل الأمر بـ--create-channel'),

            $this->row('مفتاح القناة', (bool) $channel?->ai_enabled,
                'مشغّل', 'مطفأ — شغّله من الصندوق الموحّد'),

            // `sync` يعني أن الردّ يجري داخل الـwebhook: يتجاوز المهلة وتُعيد
            // ميتا الإرسال، فيردّ الوكيل مرّتين.
            $this->row('محرّك الطابور', $queue !== 'sync',
                $queue, 'sync — الوكيل يحتاج عامل طابور: php artisan queue:work'),

            $this->row('أصناف جاهزة للبيع', $ready > 0,
                $ready.' من '.Product::count(), 'لا صنف جاهز — الوكيل سيحوّل كل سؤال'),
        ];

        $this->table(['الفحص', 'الحالة', 'النتيجة'], $rows);

        $failed = collect($rows)->filter(fn (array $r) => $r[1] === '✗')->count();

        $this->line('');

        if ($failed === 0) {
            $this->info('✓ كل شيء جاهز — أرسل رسالة إلى رقم المتجر وراقب الصندوق الموحّد.');

            return self::SUCCESS;
        }

        $this->warn("يحتاج انتباهًا: {$failed}.");
        $this->line('التفصيل في docs/AI_AGENT_OPERATIONS.md');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function row(string $label, bool $ok, string $good, string $bad): array
    {
        return [$label, $ok ? '✓' : '✗', $ok ? $good : $bad];
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
            $this->error('WHATSAPP_PHONE_NUMBER_ID غير مضبوط — لا يمكن إنشاء القناة.');

            return;
        }

        $existing = MessagingChannel::where('provider', 'whatsapp')
            ->where('external_id', $phoneNumberId)->first();

        if ($existing) {
            $this->info("✓ القناة موجودة أصلًا: {$existing->name}");

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

        $this->info("✓ أُنشئت القناة: {$channel->name}");
        $this->line('  وهي **مطفأة** — شغّلها من: التسويق ← الصندوق الموحّد.');
    }
}
