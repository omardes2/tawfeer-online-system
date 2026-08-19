<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Store\Models\CheckoutSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * متابعة الطلبات غير المكتملة برسالة تلقائية.
 *
 * أخصّ من متابعة السلال المهجورة: هذا زبونٌ بلغ خطوة الإتمام وكتب رقمه، فنيّته
 * أوضح وقيمته أعلى. والمرجع لكل جلسة يمنع تكرار الرسالة عبر التشغيلات.
 *
 * **لا يُرسَل إلّا لمن له سجلّ عميل** مطابقٌ بالرقم: حوكمة الموافقة والحجب في
 * `MessageDispatcher` تعمل على العميل، ولا يجوز مراسلة رقمٍ لم يُوافق صاحبه.
 * ومن لا سجلّ له يبقى في شاشة «طلبات لم تكتمل» لمكالمةٍ بشرية — وهي أنجح على
 * كل حال في سوق الدفع عند الاستلام.
 */
class RunAbandonedCheckoutsCommand extends Command
{
    protected $signature = 'marketing:run-abandoned-checkouts {--minutes=60 : عتبة الركود بالدقائق}';

    protected $description = 'تحفيز حملات متابعة الطلبات غير المكتملة';

    public function handle(CampaignService $campaigns): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $sessions = CheckoutSession::query()
            ->where('status', 'pending')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->where('updated_at', '<=', $threshold)
            // من سُجّل عليه أثرُ متابعة لا تُلاحقه رسالة آلية فوق المكالمة.
            ->whereNull('recovery_status')
            ->with('cart.items')
            ->get()
            ->filter(fn (CheckoutSession $s) => $s->cart
                && $s->cart->status !== 'converted'
                && $s->cart->items->isNotEmpty());

        $messages = 0;
        $matched = 0;

        foreach ($sessions as $session) {
            if (! ($customer = $this->customerFor((string) $session->customer_phone))) {
                continue;
            }

            $matched++;
            $summary = $campaigns->handleTrigger('abandoned_checkout', $customer, [
                'name' => $session->customer_name ?? $customer->name,
            ], 'abandoned_checkout:'.$session->id);

            $messages += array_sum($summary);
        }

        $this->info("Processed {$sessions->count()} checkouts, {$matched} matched customers, {$messages} messages.");

        return self::SUCCESS;
    }

    /**
     * العميل صاحب الرقم بصيغتيه.
     *
     * `0599…` و`970599…` كلتاهما مخزَّنتان في النظام ولا مُطبِّع يوحّدهما، فيُبحث
     * بهما معًا وإلّا فُقد العميل لاختلاف الصيغة وحده.
     */
    private function customerFor(string $phone): ?Customer
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 9) {
            return null;
        }

        $variants = [$digits];
        if (str_starts_with($digits, '970')) {
            $variants[] = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $variants[] = '970'.substr($digits, 1);
        }

        return Customer::query()
            ->whereIn('primary_phone', $variants)
            ->orWhereHas('phones', fn (Builder $q) => $q->whereIn('phone', $variants))
            ->first();
    }
}
