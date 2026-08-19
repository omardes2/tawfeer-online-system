<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Models\CheckoutSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * استرداد الطلبات غير المكتملة.
 *
 * جلسة الإتمام تحفظ الاسم والرقم والمدينة تدريجيًّا كلّما زامنت الواجهةُ الخلفية،
 * فمن ملأ بياناته ثم تردّد في الخطوة الأخيرة يبقى رقمه عندنا. هؤلاء أدفأ جمهورٍ
 * في النظام — دُفع ثمن إعلانٍ ليصلوا، واختاروا الصنف، وكتبوا عنوانهم — ولا أحد
 * يراهم اليوم لأن متابعة السلال المهجورة تشترط عميلًا مسجَّلًا، ومعظم المشترين
 * ضيوف.
 *
 * **حدُّ التغطية:** الرقم لا يصل الخلفية إلا عند مزامنةٍ، والمزامنة تقع عند
 * اختيار المدينة أو المنطقة. فمن كتب رقمه وغادر قبل بلوغ المدينة لا أثر له.
 * تغطيته تقتضي إضافة مستمعِ حدثٍ على حقل الهاتف داخل تسلسل Checkout المجمّد،
 * فتُركت كما هي وذُكرت هنا.
 */
class CheckoutRecoveryService
{
    /** لا يُزعَج من هو الآن في منتصف الشراء. */
    private const DEFAULT_QUIET_MINUTES = 30;

    /**
     * صفوف قائمة الاتصال.
     *
     * @param  array{status?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(DateRange $range, array $filters = []): Collection
    {
        $rows = $this->rows($range);
        $status = $filters['status'] ?? 'open';

        if ($status === 'open') {
            return $rows->where('is_open', true)->values();
        }

        if ($status && $status !== 'all') {
            return $rows->where('status', $status)->values();
        }

        return $rows;
    }

    /**
     * الملخّص فوق القائمة — يُحسب على الفترة كلّها لا على التصفية، وإلّا تغيّر
     * «الضائع» كلّما بدّل الموظف الفلتر.
     *
     * @return array<string, float|int>
     */
    public function stats(DateRange $range): array
    {
        $rows = $this->rows($range);
        $open = $rows->where('is_open', true);
        $recovered = $rows->where('status', 'recovered');

        return [
            'count' => $rows->count(),
            'open_count' => $open->count(),
            'open_value' => round((float) $open->sum('value'), 2),
            'recovered_count' => $recovered->count(),
            'recovered_value' => round((float) $recovered->sum('recovered_total'), 2),
        ];
    }

    /** تسجيل نتيجة الاتصال. */
    public function markOutcome(CheckoutSession $session, string $status, ?string $note, User $actor): CheckoutSession
    {
        $session->update([
            'recovery_status' => $status,
            'recovery_note' => $note,
            'recovery_user_id' => $actor->id,
            'recovery_contacted_at' => now(),
            // محاولةٌ تُعدّ لكل تسجيلٍ إلّا التجاهل: «لا يرد» ثلاث مرّات إشارةٌ
            // إلى الكفّ عن المحاولة، وهي لا تظهر بلا عدّاد.
            'recovery_attempts' => $status === 'ignored'
                ? $session->recovery_attempts
                : $session->recovery_attempts + 1,
        ]);

        return $session->refresh();
    }

    /**
     * الجلسات المعلّقة الصالحة للاتصال، مُزالٌ منها المكرّر ومُعلَّمٌ فيها من
     * اشترى لاحقًا.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(DateRange $range): Collection
    {
        $quiet = now()->subMinutes(max(0, (int) Settings::get('store.abandoned_checkout_minutes', self::DEFAULT_QUIET_MINUTES)));

        $sessions = CheckoutSession::query()
            ->where('status', 'pending')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->whereBetween('created_at', $range->bounds())
            // مهلة الهدوء تحرس من هو الآن في منتصف الشراء. ومن سُجّلت عليه نتيجة
            // اتصالٍ فقد حُكم بتركه أصلًا، ولولا هذا الاستثناء لاختفى الصفّ من
            // أمام الموظف لحظةَ حفظه — لأن الحفظ نفسه يحدّث `updated_at`.
            ->where(fn (Builder $q) => $q->where('updated_at', '<=', $quiet)->orWhereNotNull('recovery_status'))
            ->with(['cart.items.variant.product', 'city', 'area', 'recoveryUser', 'recoveryOrder'])
            ->latest('id')
            ->get()
            // سلّةٌ فرغت أو تحوّلت إلى طلبٍ بجلسةٍ أخرى ليست طلبًا ضائعًا.
            ->filter(fn (CheckoutSession $s) => $s->cart
                && $s->cart->status !== 'converted'
                && $s->cart->items->isNotEmpty());

        // زبونٌ واحد يعود مرّاتٍ فتُنشأ له جلساتٌ عدّة. تُبقى الأحدث وحدها كي لا
        // يتّصل الموظّف بالرجل ثلاث مرّات في يوم.
        $latest = [];
        $repeats = [];
        foreach ($sessions as $session) {
            $key = $this->phoneKey((string) $session->customer_phone);
            if (isset($latest[$key])) {
                $repeats[$key] = ($repeats[$key] ?? 1) + 1;

                continue;
            }
            $latest[$key] = $session;
        }

        $orders = $this->laterOrders(collect($latest));

        return collect($latest)->map(function (CheckoutSession $session, string $key) use ($orders, $repeats) {
            $order = ($orders[$key] ?? collect())
                ->first(fn (Order $o) => $o->created_at->greaterThan($session->created_at));

            return $this->row($session, $order, $repeats[$key] ?? 1);
        })->values();
    }

    /**
     * طلبات أُنشئت بأرقام هؤلاء الزبائن — من اشترى بعد ترك سلّته لا يُتصل به.
     *
     * مطابقةٌ بآخر تسعة أرقام: النظام يخزّن الرقم أحيانًا «0599…» وأحيانًا
     * «970599…»، ولا مُطبِّع يوحّدهما.
     *
     * @param  Collection<string, CheckoutSession>  $sessions
     * @return array<string, Collection<int, Order>>
     */
    private function laterOrders(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $since = $sessions->map(fn (CheckoutSession $s) => $s->created_at)->min();

        // يُقيَّد الاستعلام بأرقام هؤلاء وحدهم لا بكل طلبات الفترة: النطاق قد يكون
        // سنةً كاملة، وتحميل طلباتها جميعًا لمطابقة عشرين رقمًا إسرافٌ لا داعي له.
        // `unique()` تقارن مقارنةً متساهلة، و«0599123456» و«599123456» رقمان
        // متساويان عدديًّا — فتُسقط الصيغة المحلّية ولا يُطابَق شيء. المقارنة
        // الصارمة شرطٌ لا تحسين.
        $candidates = $sessions->keys()
            ->flatMap(fn (string $key) => [$key, '0'.$key, '970'.$key])
            ->unique(strict: true)->values()->all();

        return Order::query()
            ->whereNotNull('number')
            ->where('created_at', '>=', $since)
            ->whereIn('customer_phone', $candidates)
            ->get(['id', 'uuid', 'number', 'customer_phone', 'total', 'created_at'])
            ->groupBy(fn (Order $o) => $this->phoneKey((string) $o->customer_phone))
            ->map(fn (Collection $group) => $group->sortBy('created_at')->values())
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(CheckoutSession $session, ?Order $order, int $sessions): array
    {
        $cart = $session->cart;
        $stored = $session->recovery_status ?: 'new';
        // الطلب اللاحق يحسم الحالة مهما كان المسجَّل: من اشترى فعلًا لا يُعاد
        // إلى قائمة الاتصال لأن أحدًا نسي تحديث حالته.
        $status = $order ? 'recovered' : $stored;

        return [
            'uuid' => $session->uuid,
            'created_at' => $session->created_at,
            'name' => $session->customer_name,
            'phone' => $session->customer_phone,
            'city' => $session->city?->name,
            'area' => $session->area?->name,
            'address' => $session->shipping_address,
            'items' => $cart->items->map(fn ($i) => [
                'name' => $i->variant?->product?->name ?? __('صنف محذوف'),
                'qty' => (float) $i->qty,
            ])->all(),
            'value' => round($cart->subtotal(), 2),
            'sessions' => $sessions,
            'status' => $status,
            'is_open' => in_array($status, CheckoutSession::OPEN_RECOVERY_STATUSES, true),
            'note' => $session->recovery_note,
            'attempts' => (int) $session->recovery_attempts,
            'contacted_at' => $session->recovery_contacted_at,
            'contacted_by' => $session->recoveryUser?->name,
            // الطلب المرتبط: المسجَّل يدويًّا إن وُجد، وإلّا المكتشَف بالرقم.
            'recovered_order' => $session->recoveryOrder?->number ?? $order?->number,
            'recovered_order_uuid' => $session->recoveryOrder?->uuid ?? $order?->uuid,
            'recovered_total' => round((float) ($session->recoveryOrder?->total ?? $order?->total ?? 0), 2),
        ];
    }

    /** آخر تسعة أرقام — صيغةٌ واحدة للرقم مهما كُتب. */
    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }
}
