<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\MessageSuppression;
use App\Support\Integrations\Messaging\MessagingManager;
use Illuminate\Support\Str;

/**
 * مُرسِل رسائل التسويق (Phase 6 / ADR-046).
 *
 * **مستقلّ عن المزوّد تمامًا** — يرسل حصريًا عبر MessagingManager (القناة → Driver من
 * الإعداد). يطبّق قواعد الحوكمة قبل أي إرسال: **موافقة (opt-in)**، حجب (opt-out/suppression)،
 * ساعات هدوء، حدّ تكرار، و**idempotency** (لا تكرار للرسالة نفسها). كل محاولة تُسجَّل مع
 * حالتها (sent/failed/skipped/…) بلا أسرار. الاختبارات تستخدم مزوّدًا وهميًا — لا إرسال حقيقي.
 */
class MessageDispatcher
{
    public function __construct(private readonly MessagingManager $messaging) {}

    /**
     * إرسال رسالة حملة إلى عميل. تُعيد سجلّ CampaignMessage بحالته النهائية.
     *
     * @param  array<string, mixed>  $vars  متغيّرات القالب (name/order_no/…)
     */
    public function dispatch(Campaign $campaign, Customer $customer, array $vars = [], bool $isTest = false, ?string $reference = null): CampaignMessage
    {
        $channel = $campaign->channel;
        $recipient = $this->recipientFor($customer, $channel);

        // idempotency: مفتاح ثابت للثلاثي (حملة/عميل/مرجع) يمنع الإرسال المكرّر.
        $idempotencyKey = $this->idempotencyKey($campaign, $customer, $reference, $isTest);
        if ($existing = CampaignMessage::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $message = new CampaignMessage([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'recipient' => $recipient,
            'body' => $this->render($campaign, $customer, $vars),
            'idempotency_key' => $idempotencyKey,
            'is_test' => $isTest,
            'attempts' => 0,
        ]);

        // بوّابات الحوكمة (تُتجاوز حوكمة الجمهور فقط للاختبار المباشر، لا الحجب).
        if ($recipient === null) {
            return $this->finalize($message, 'no_contact');
        }
        if ($this->isSuppressed($customer, $recipient, $channel)) {
            return $this->finalize($message, 'suppressed');
        }
        if (! $isTest) {
            if (! $this->hasConsent($customer, $channel)) {
                return $this->finalize($message, 'no_consent');
            }
            if ($this->inQuietHours($campaign)) {
                return $this->finalize($message, 'quiet_hours');
            }
            if ($this->frequencyCapped($campaign, $customer)) {
                return $this->finalize($message, 'frequency_capped');
            }
        }

        return $this->send($message, $channel, $recipient);
    }

    /** محاولة الإرسال الفعلي عبر المزوّد وتسجيل النتيجة. قابلة لإعادة المحاولة. */
    public function send(CampaignMessage $message, ?string $channel = null, ?string $recipient = null): CampaignMessage
    {
        $channel ??= $message->channel;
        $recipient ??= $message->recipient;
        $message->attempts = ($message->attempts ?? 0) + 1;

        try {
            $result = $this->messaging->for($channel)->send($channel, $recipient, $message->body, [
                'campaign_id' => $message->campaign_id,
                'customer_id' => $message->customer_id,
            ]);

            $status = ($result['status'] ?? 'sent') === 'skipped' ? 'skipped' : 'sent';

            return $this->finalize($message, $status, $result['reference'] ?? null, $status === 'sent');
        } catch (\Throwable $e) {
            return $this->finalize($message, 'failed', null, false, mb_substr($e->getMessage(), 0, 200));
        }
    }

    private function finalize(CampaignMessage $message, string $status, ?string $reference = null, bool $sent = false, ?string $error = null): CampaignMessage
    {
        $message->status = $status;
        $message->provider_reference = $reference;
        $message->error = $error;
        $message->sent_at = $sent ? now() : $message->sent_at;
        $message->save();

        return $message;
    }

    private function recipientFor(Customer $customer, string $channel): ?string
    {
        return match ($channel) {
            'whatsapp', 'sms' => $customer->primary_phone ?: null,
            'email' => $customer->email ?: null,
            'internal' => (string) $customer->id,
            default => $customer->primary_phone ?: $customer->email ?: null,
        };
    }

    /** الموافقة عبر communication_preferences (opt-in). القناة الداخلية دائمًا مسموحة. */
    private function hasConsent(Customer $customer, string $channel): bool
    {
        if ($channel === 'internal') {
            return true;
        }
        $prefs = (array) ($customer->communication_preferences ?? []);

        return (bool) ($prefs[$channel] ?? false);
    }

    private function isSuppressed(Customer $customer, string $recipient, string $channel): bool
    {
        return MessageSuppression::query()
            ->where('channel', $channel)
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('contact', $recipient))
            ->exists();
    }

    private function inQuietHours(Campaign $campaign): bool
    {
        if ($campaign->quiet_start === null || $campaign->quiet_end === null) {
            return false;
        }
        $hour = (int) now()->format('G');
        $start = (int) $campaign->quiet_start;
        $end = (int) $campaign->quiet_end;

        // نافذة قد تلتفّ حول منتصف الليل (مثال 22 → 7).
        return $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
    }

    private function frequencyCapped(Campaign $campaign, Customer $customer): bool
    {
        $days = (int) ($campaign->frequency_cap_days ?? 0);
        if ($days <= 0) {
            return false;
        }

        // فقط الرسائل المُرسَلة فعليًا تحسب ضمن الحدّ (العميل جرى التواصل معه).
        return CampaignMessage::query()
            ->where('customer_id', $customer->id)
            ->where('is_test', false)
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->subDays($days))
            ->exists();
    }

    private function idempotencyKey(Campaign $campaign, Customer $customer, ?string $reference, bool $isTest): string
    {
        // بلا مرجع: مفتاح يومي لكل (حملة/عميل) لمنع تكرار نفس اليوم؛ الاختبار مفتاح فريد.
        $ref = $reference ?? ($isTest ? 'test-'.Str::random(8) : now()->format('Ymd'));

        return "c{$campaign->id}:u{$customer->id}:{$ref}".($isTest ? ':test' : '');
    }

    private function render(Campaign $campaign, Customer $customer, array $vars): string
    {
        $locale = $customer->preferred_locale === 'en' ? 'en' : 'ar';
        $body = $locale === 'en'
            ? ($campaign->body_en ?: $campaign->body_ar)
            : ($campaign->body_ar ?: $campaign->body_en);
        $body = (string) ($body ?? '');

        $replacements = array_merge([
            'name' => $customer->name,
            'first_name' => Str::before((string) $customer->name, ' '),
        ], $vars);

        foreach ($replacements as $key => $value) {
            $body = str_replace(['{{'.$key.'}}', '{{ '.$key.' }}'], (string) $value, $body);
        }

        return $body;
    }
}
