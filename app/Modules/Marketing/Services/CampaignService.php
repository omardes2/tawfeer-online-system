<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * خدمة أتمتة التسويق (Phase 6 / ADR-046).
 *
 * تدير دورة حياة الحملة (مسودّة → اعتماد → تفعيل → إيقاف) والتنفيذ عبر MessageDispatcher
 * (مستقلّ عن المزوّد). لا تستدعي مزوّدًا مباشرةً ولا ترسل أثناء الاختبارات (مزوّد وهمي).
 * التحفيز بحدث يمرّ عبر handleTrigger مع مرجع idempotency مستمدّ من الحدث.
 */
class CampaignService
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Campaign
    {
        return Campaign::create(array_merge([
            'status' => 'draft',
            'created_by' => $actor?->id,
        ], $data));
    }

    public function transition(Campaign $campaign, string $to, ?User $actor = null): Campaign
    {
        if (! Campaign::canTransition($campaign->status, $to)) {
            throw ValidationException::withMessages([
                'status' => __('انتقال غير مسموح: :from → :to', ['from' => $campaign->status, 'to' => $to]),
            ]);
        }

        $campaign->status = $to;
        if ($to === 'approved') {
            $campaign->approved_by = $actor?->id;
            $campaign->approved_at = now();
        }
        $campaign->save();

        return $campaign;
    }

    /**
     * تنفيذ حملة على جمهورها المُحلّل (مجدولة/يدوية). تُعيد ملخّص النتائج حسب الحالة.
     *
     * @return array<string, int>
     */
    public function run(Campaign $campaign, ?string $reference = null): array
    {
        if ($campaign->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('الحملة غير مفعّلة.')]);
        }

        $customers = $this->audience->resolve($campaign);

        return $this->dispatchMany($campaign, $customers, $reference);
    }

    /**
     * تحفيز بحدث (Phase 6): يوصل عميل الحدث لكل حملة مفعّلة تطابق الحدث.
     * المرجع يضمن idempotency (مثال: "delivered:{orderId}").
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, int>
     */
    public function handleTrigger(string $event, Customer $customer, array $vars = [], ?string $reference = null): array
    {
        $campaigns = Campaign::query()->active()
            ->where('trigger_type', 'event')
            ->where('trigger_event', $event)
            ->get();

        $summary = [];
        foreach ($campaigns as $campaign) {
            $ref = $reference ?? $event.':'.$customer->id;
            $message = $this->dispatcher->dispatch($campaign, $customer, $vars, false, $ref);
            $summary[$message->status] = ($summary[$message->status] ?? 0) + 1;
        }

        return $summary;
    }

    /** إرسال تجريبي لعميل واحد (يتجاوز حوكمة الجمهور، لا الحجب). */
    public function test(Campaign $campaign, Customer $customer): CampaignMessage
    {
        return $this->dispatcher->dispatch($campaign, $customer, [], true);
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<string, int>
     */
    private function dispatchMany(Campaign $campaign, Collection $customers, ?string $reference): array
    {
        $summary = [];
        foreach ($customers as $customer) {
            $message = $this->dispatcher->dispatch($campaign, $customer, [], false, $reference);
            $summary[$message->status] = ($summary[$message->status] ?? 0) + 1;
        }

        return $summary;
    }
}
