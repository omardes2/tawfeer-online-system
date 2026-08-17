<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\CampaignTemplate;
use App\Modules\Marketing\Models\MarketingContact;
use App\Support\Integrations\Messaging\MessagingManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إرسال قالبٍ معتمَد إلى شريحةٍ من جهات الاتصال — بدفعاتٍ محكومة.
 *
 * **مسارٌ مستقلّ عن `MessageDispatcher` عمدًا.** ذاك مبنيّ على العميل: يقرأ
 * موافقته من تفضيلاته ويحسب سقف تكراره على سجلّه. وجهةُ الاتصال ليست عميلًا،
 * ولها حرّاسها هي — الموافقة المسجَّلة عند الاستيراد، والحجب. وحشرُ أحدهما في
 * الآخر كان سيُضعف الاثنين.
 *
 * وخمسة حرّاس تحكم كل تشغيلة:
 *
 * 1. **من لا يجوز مراسلته لا يُقرَأ أصلًا** — `sendable()` تُصفّي الموافقة
 *    والحجب في الاستعلام لا بعده، فلا يمرّ رقمٌ سهوًا.
 *
 * 2. **الحدّ اليومي هو طبقة رقمك لدى المنصّة لا رغبتك.** الرقم الجديد يبدأ عند
 *    مئتين وخمسين مستلمًا في اليوم ويرتفع مع الجودة؛ وتجاوزه يُرفَض، وتكرار
 *    الرفض يُسقط الجودة. يُحسب المُرسَل اليوم **عبر الحملات كلّها** لا حملةً
 *    حملة — المنصّة تعدّ الرقم لا الحملة.
 *
 * 3. **لا تكرار.** جهةٌ رُوسلت في هذه الحملة لا تُراسَل ثانيةً، ولو أُعيد
 *    تشغيل الأمر. الحارس مفتاحٌ فريد في القاعدة لا فحصٌ في الذاكرة.
 *
 * 4. **إيقافٌ تلقائي عند ارتفاع الفشل.** الفشل المتراكم مؤشّرُ قائمةٍ رديئة أو
 *    قالبٍ مرفوض، والاستمرار عليه يُحرق الرقم. ولا يُصدَّق قبل عيّنةٍ كافية —
 *    إخفاقان من اثنين ليسا دليلًا.
 *
 * 5. **الأحدث شراءً أولًا.** من اشترى قريبًا يتذكّرك فيحجب أقلّ. والبدء
 *    بالأقدم يُنتج موجة حجبٍ تقتل الرقم قبل أن يصل إلى الشريحة المربحة.
 */
class ContactBroadcastService
{
    public function __construct(private readonly MessagingManager $messaging) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{sent: int, failed: int, skipped: int, remaining_today: int, aborted: bool, reason: ?string}
     */
    public function run(CampaignTemplate $template, array $options = []): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'remaining_today' => 0, 'aborted' => false, 'reason' => null];

        if (blank($template->provider_template)) {
            $summary['reason'] = __('القالب بلا اسمٍ معتمَد لدى المنصّة — النصّ وحده لا يُرسَل تسويقيًّا.');

            return $summary;
        }

        $dailyLimit = (int) config('messaging.bulk.daily_limit', 250);
        $sentToday = $this->sentToday();
        $room = max(0, $dailyLimit - $sentToday);

        if ($room === 0) {
            $summary['reason'] = __('بلغتَ الحدّ اليومي (:n) — الباقي غدًا.', ['n' => $dailyLimit]);

            return $summary;
        }

        $batch = min($room, (int) ($options['limit'] ?? config('messaging.bulk.batch', 50)));
        $contacts = $this->nextContacts($template, $batch, $options);

        foreach ($contacts as $contact) {
            $outcome = $this->sendOne($template, $contact, $options);
            $summary[$outcome]++;

            if ($this->shouldAbort($summary)) {
                $summary['aborted'] = true;
                $summary['reason'] = __('توقّف تلقائيًّا: نسبة الفشل تجاوزت :p%.', [
                    'p' => (int) config('messaging.bulk.abort_failure_pct', 20),
                ]);

                Log::warning('whatsapp.bulk.aborted', $summary);

                break;
            }
        }

        $summary['remaining_today'] = max(0, $dailyLimit - $this->sentToday());

        return $summary;
    }

    /**
     * الدفعة التالية — الأحدث شراءً أولًا، ومن لم يُراسَل في هذه الحملة.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, MarketingContact>
     */
    private function nextContacts(CampaignTemplate $template, int $limit, array $options)
    {
        return MarketingContact::query()
            ->sendable()
            ->when($options['customers_only'] ?? false, fn ($q) => $q->whereNotNull('customer_id'))
            // من رُوسل في هذه الحملة لا يُعاد — الحارس في الاستعلام لا بعده.
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('campaign_messages')
                ->whereColumn('campaign_messages.marketing_contact_id', 'marketing_contacts.id')
                ->where('campaign_messages.campaign_id', $this->campaignIdFor($template)))
            /*
            | الأحدث شراءً أولًا: العميل المرتبط قبل المجهول، والأحدث تسجيلًا
            | قبل الأقدم. ترتيبٌ بسيط، وأثره أن موجة الحجب الأولى — إن جاءت —
            | تأتي من أكثر الشرائح تسامحًا لا أقلّها.
            */
            ->orderByRaw('CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * إرسالٌ واحد — ويعيد اسم العدّاد الذي يزيد.
     *
     * @param  array<string, mixed>  $options
     */
    private function sendOne(CampaignTemplate $template, MarketingContact $contact, array $options): string
    {
        $campaignId = $this->campaignIdFor($template);
        $key = 'contact:'.$campaignId.':'.$contact->id;

        // الحفظ قبل الإرسال: نداءٌ نجح ثم انقطع الاتصال يترك المنصّة قد أرسلت
        // وسجلَّنا فارغًا، فتُعاد الرسالة غدًا على من استلمها.
        $message = CampaignMessage::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'campaign_id' => $campaignId,
                'customer_id' => $contact->customer_id,
                'marketing_contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'recipient' => $contact->phone,
                'body' => $template->body_ar,
                'status' => 'queued',
            ],
        );

        if (! $message->wasRecentlyCreated) {
            return 'skipped';
        }

        try {
            $result = $this->messaging->for('whatsapp')->send('whatsapp', $contact->phone, (string) $template->body_ar, [
                'template' => $template->provider_template,
                'language' => $template->provider_language ?: config('messaging.whatsapp.default_language', 'ar'),
                'params' => $template->orderedParams($this->varsFor($contact, $options)),
            ]);

            $status = ($result['status'] ?? 'sent') === 'skipped' ? 'skipped' : 'sent';

            $message->update([
                'status' => $status,
                'provider_reference' => $result['reference'] ?? null,
                'attempts' => 1,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);

            if ($status === 'sent') {
                $contact->update(['last_contacted_at' => now()]);
            }

            return $status === 'sent' ? 'sent' : 'skipped';
        } catch (Throwable $e) {
            $message->update([
                'status' => 'failed',
                'attempts' => 1,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return 'failed';
        }
    }

    /**
     * متغيّرات القالب لهذه الجهة.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function varsFor(MarketingContact $contact, array $options): array
    {
        return array_merge(
            (array) ($options['vars'] ?? []),
            ['customer_name' => $contact->name ?: __('عميلنا العزيز')],
        );
    }

    /** ما أُرسل اليوم من الرقم كلّه — المنصّة تعدّ الرقم لا الحملة. */
    private function sentToday(): int
    {
        return CampaignMessage::where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->whereDate('sent_at', now()->toDateString())
            ->count();
    }

    /** @param  array<string, mixed>  $summary */
    private function shouldAbort(array $summary): bool
    {
        $attempts = $summary['sent'] + $summary['failed'];
        $minSample = (int) config('messaging.bulk.abort_min_sample', 20);

        if ($attempts < $minSample) {
            return false;
        }

        return ($summary['failed'] / $attempts) * 100 >= (int) config('messaging.bulk.abort_failure_pct', 20);
    }

    /**
     * الحملة المرتبطة بالقالب.
     *
     * القالب لا يحمل حملة، والرسائل تحتاج واحدة. تُستعمل حملةٌ واحدة لكل قالب
     * باسمه، تُنشأ عند أول إرسال — فيبقى السجلّ مجمّعًا ولا يُنشأ صفٌّ يتيم.
     */
    private function campaignIdFor(CampaignTemplate $template): int
    {
        return Campaign::firstOrCreate(
            ['name' => 'broadcast:'.$template->id],
            [
                'use_case' => 'win_back',
                'channel' => 'whatsapp',
                'status' => 'active',
                'trigger_type' => 'manual',
                'template_id' => $template->id,
            ],
        )->id;
    }
}
