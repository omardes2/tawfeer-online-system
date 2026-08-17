<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\MarketingContact;
use Illuminate\Support\Facades\Log;

/**
 * تطبيق حالات واتساب الواردة على الرسائل وجهات الاتصال.
 *
 * ثلاثة قرارات:
 *
 * 1. **الحالة لا ترجع للوراء.** تصل الحالات خارج ترتيبها أحيانًا («سُلّمت» بعد
 *    «قُرئت»)، وكتابةُ الأحدث فوق الأقدم بلا ترتيبٍ تُنزل رسالةً مقروءة إلى
 *    «مُرسَلة». فلكل حالةٍ رتبة، ولا تُكتب إلّا إن كانت أعلى.
 *
 * 2. **الفشل الدائم يُخرج الرقم من القائمة.** رقمٌ لا وجود له على واتساب، أو
 *    رفض استقبال رسائلنا، تُعاد محاولته في كل حملةٍ فيتراكم الفشل وتسقط درجة
 *    جودة الرقم. يُوسَم `blocked_at` مرّة، فلا يُراسَل ثانيةً.
 *
 * 3. **الفشل العابر لا يُوسَم.** «تجاوزتَ الحدّ اليومي» ليس عيبًا في الرقم بل
 *    فينا، ووسمُه كان سيحرق قائمتنا بأيدينا في أول تشغيلةٍ متعجّلة.
 */
class WhatsAppStatusService
{
    /** رتبة الحالات — الأعلى لا يُكتب فوقه أدنى منه. */
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /**
     * رموز فشلٍ دائم تعني «لا تُراسل هذا الرقم ثانيةً».
     *
     * 131026 رسالة غير قابلة للتسليم · 131047 يحتاج إعادة تواصلٍ من الزبون ·
     * 131050 لا يستقبل رسائل تسويقية · 132015 القالب موقوف لهذا المستلم.
     *
     * وما عداها عابر: حدٌّ يومي، أو عطلٌ لدى المنصّة، أو قالبٌ يُراجَع.
     */
    private const PERMANENT_FAILURES = [131026, 131047, 131050, 132015];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{statuses: int, blocked: int}
     */
    public function apply(array $payload): array
    {
        $summary = ['statuses' => 0, 'blocked' => 0];

        foreach ($this->statusRows($payload) as $row) {
            $reference = (string) ($row['id'] ?? '');
            $status = (string) ($row['status'] ?? '');

            if ($reference === '' || ! isset(self::RANK[$status])) {
                continue;
            }

            $message = CampaignMessage::where('provider_reference', $reference)->first();

            if ($message && $this->outranks($status, (string) $message->status)) {
                $message->update([
                    'status' => $status,
                    'error' => $status === 'failed' ? $this->errorText($row) : $message->error,
                ]);
                $summary['statuses']++;
            }

            if ($status === 'failed' && $this->isPermanent($row)) {
                $summary['blocked'] += $this->block((string) ($row['recipient_id'] ?? ''), $message);
            }
        }

        return $summary;
    }

    /**
     * صفوف الحالة من حمولة ميتا المتشعّبة.
     *
     * البنية `entry[].changes[].value.statuses[]`، وأي جزءٍ منها قد يغيب في
     * إشعارٍ من نوعٍ آخر (رسالة واردة مثلًا) — فتُقرأ بحذرٍ لا بافتراض.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function statusRows(array $payload): array
    {
        $rows = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        $rows[] = $status;
                    }
                }
            }
        }

        return $rows;
    }

    private function outranks(string $incoming, string $current): bool
    {
        return (self::RANK[$incoming] ?? 0) > (self::RANK[$current] ?? 0);
    }

    /** @param  array<string, mixed>  $row */
    private function isPermanent(array $row): bool
    {
        foreach ((array) ($row['errors'] ?? []) as $error) {
            if (in_array((int) ($error['code'] ?? 0), self::PERMANENT_FAILURES, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $row */
    private function errorText(array $row): ?string
    {
        $error = ((array) ($row['errors'] ?? []))[0] ?? null;

        if (! is_array($error)) {
            return null;
        }

        return mb_substr(trim('['.($error['code'] ?? '?').'] '.($error['title'] ?? $error['message'] ?? '')), 0, 200);
    }

    /**
     * وسمُ الرقم بأنه لا يستقبل — بالرقم الوارد أو برقم الرسالة.
     *
     * `recipient_id` من المنصّة يأتي بالصيغة الدولية بلا رموز، وهي الصيغة
     * نفسها التي نخزّن بها جهات الاتصال — فالمطابقة مباشرة.
     */
    private function block(string $recipientId, ?CampaignMessage $message): int
    {
        $phone = $recipientId !== '' ? $recipientId : (string) ($message->recipient ?? '');

        if ($phone === '') {
            return 0;
        }

        $updated = MarketingContact::where('phone', $phone)
            ->whereNull('blocked_at')
            ->update(['blocked_at' => now()]);

        if ($updated > 0) {
            Log::info('whatsapp.contact.blocked', ['phone' => $phone]);
        }

        return $updated;
    }
}
