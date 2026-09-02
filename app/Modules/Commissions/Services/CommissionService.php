<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Commissions\Events\CommissionAccrued;
use App\Modules\Commissions\Events\CommissionAdjusted;
use App\Modules\Commissions\Events\CommissionReversed;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Models\CommissionTransition;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك دفتر العمولات/الأرباح (Phase 4.2 / ADR-037). دفتر غير قابل للتعديل (المبالغ)،
 * آلة حالات محروسة، أولويّة قواعد حتمية، وجاهزية مرتجعات (تعديل/عكس تناسبي بلا مسّ
 * التاريخ). الاستحقاق (eligible) يبقى مغلقًا حتى التسوية (4.6). لا محاسبة نهائية هنا.
 */
class CommissionService
{
    private const DEFAULT_SALES_RATE = 0.01; // 1% افتراضي (BR-MKT/ADR-012).

    /**
     * وسمُ تصحيح لقطة الجملة داخل `rule_snapshot`.
     *
     * به يُعرَف ما صُحّح فلا يُصحَّح ثانيةً — والأمر يُشغَّل أكثر من مرّة
     * بطبيعته: عرضٌ، ثم تنفيذ، ثم تحقّق. وبلا وسمٍ يخصم كلُّ تشغيلٍ الفارقَ
     * من جديد.
     */
    private const WHOLESALE_CORRECTION = 'wholesale_snapshot';

    public function __construct(private readonly PriceListService $prices) {}

    /** الانتقالات المسموحة لآلة الحالة. */
    private const TRANSITIONS = [
        'pending' => ['eligible', 'cancelled', 'reversed'],
        // `paid` من `eligible` مباشرةً للتسوية اليدوية: البند صُرف فعلًا بسندٍ
        // سابق، والاعتمادُ خطوةٌ تسبق الصرف — فلا معنى للمرور بها بعده.
        'eligible' => ['approved', 'paid', 'cancelled', 'reversed'],
        'approved' => ['paid', 'reversed'],
        'paid' => ['reversed'],
    ];

    /**
     * أولويّة القاعدة من أخصّ نطاق مضبوط (المتطلّب 5): موظف > حملة > منتج/فئة > فرع > دور > عام.
     */
    public function ruleScorePriority(CommissionRule $rule): int
    {
        return match (true) {
            $rule->user_id !== null => 6,
            $rule->campaign !== null => 5,
            $rule->product_id !== null || $rule->category_id !== null => 4,
            $rule->branch_id !== null => 3,
            $rule->role !== null => 2,
            default => 1,
        };
    }

    /**
     * حلّ القاعدة المطبَّقة حتميًا: أعلى أولويّة مطابقة نشطة ضمن الفترة، ثم الأحدث.
     *
     * @param  array<string, mixed>  $ctx  user_id/product_id/category_id/branch_id/role/campaign/date
     */
    public function resolveRule(string $earnerType, array $ctx): ?CommissionRule
    {
        $date = $ctx['date'] ?? now()->toDateString();

        $rules = CommissionRule::where('earner_type', $earnerType)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('period_start')->orWhere('period_start', '<=', $date))
            ->where(fn ($q) => $q->whereNull('period_end')->orWhere('period_end', '>=', $date))
            ->get()
            ->filter(fn (CommissionRule $r) => $this->ruleMatches($r, $ctx));

        return $rules
            ->sortByDesc(fn (CommissionRule $r) => [$this->ruleScorePriority($r), $r->id])
            ->first();
    }

    /** @param  array<string, mixed>  $ctx */
    private function ruleMatches(CommissionRule $rule, array $ctx): bool
    {
        foreach (['user_id', 'product_id', 'category_id', 'branch_id', 'role', 'campaign'] as $dim) {
            if ($rule->{$dim} !== null && (string) $rule->{$dim} !== (string) ($ctx[$dim] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** استحقاق عمولات الطلب عند التسليم (pending) — idempotent. */
    public function accrueForOrder(Order $order): void
    {
        if (CommissionEntry::where('order_id', $order->id)->where('entry_type', 'accrual')->exists()) {
            return; // لا استحقاق مزدوج.
        }

        $this->accrueItems($order);
    }

    /**
     * إعادة استحقاق عمولات طلبٍ عُدِّلت بنوده — بعد عكس القديمة.
     *
     * `accrueForOrder` تحرس نفسها بوجود **أيّ** استحقاق ولو كان معكوسًا، وهو
     * الصحيح لمنع الازدواج — لكنه يمنع إعادة الاحتساب بعد تعديل الفاتورة، فتبقى
     * الحركات معكوسةً بلا بديل ويخسر البائع عمولته كلَّها.
     *
     * فهذه تقرأ **الحيّ وحده**: إن بقي استحقاقٌ غير معكوس فلا شيء يُعاد.
     */
    public function reaccrueForOrder(Order $order): void
    {
        $live = CommissionEntry::where('order_id', $order->id)
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->exists();

        if ($live) {
            return;
        }

        $this->accrueItems($order);
    }

    /** جسم الاستحقاق — يُقرأ من موضعٍ واحد فلا يفترق الأول عن الإعادة. */
    private function accrueItems(Order $order): void
    {
        $order->loadMissing('items.variant');

        DB::transaction(function () use ($order) {
            if ($order->assigned_to) {
                foreach ($order->items as $item) {
                    $this->accrueSales($order, $item);
                }
            }
            if ($order->affiliate_id) {
                foreach ($order->items as $item) {
                    $this->accrueAffiliate($order, $item);
                }
            }
        });
    }

    private function accrueSales(Order $order, OrderItem $item): void
    {
        $rule = $this->resolveRule('sales', [
            'user_id' => $order->assigned_to, 'product_id' => $item->variant?->product_id,
            'branch_id' => $order->branch_id,
        ]);

        // أساس عمولة موظف المبيعات هو **قيمة المبيعات** دائمًا (بعد الخصم، بلا توصيل) —
        // لا هامش الربح (قرار المالك). لذلك «هامش الربح» متاح للمسوّقين فقط.
        $basis = (float) $item->qty * (float) $item->unit_price - (float) $item->discount;

        [$amount, $rate] = $this->computeAmount($rule, 'sales', $basis, self::DEFAULT_SALES_RATE);

        $this->createAccrual($order, $item, 'sales', $order->assigned_to, $basis, $rate, $amount, null, $rule);
    }

    /**
     * **سعر الجملة** وقت البيع — أساس ربح المسوّق (يشتري بالجملة ويبيع بالمفرّق)،
     * لا تكلفة الشراء (WAC) التي تخصّ تكلفة البضاعة المباعة محاسبيًا. الاحتياط:
     * سعر الجملة الحالي للمتغيّر، ثم التكلفة إن لم يُحدَّد سعر جملة أصلًا.
     */
    private function itemCost(OrderItem $item): float
    {
        // الاحتياط يشمل سعر المنتج: بنودٌ قديمة جُمّدت بلقطةٍ صفرًا لأن عمود
        // المتغيّر كان فارغًا، فكانت العمولة تُحسب على التكلفة — والتكلفة أدنى
        // من الجملة، فالهامش أكبر والعمولة أعلى مما تستحقّ.
        $wholesale = $item->wholesale_price_snapshot > 0
            ? $item->wholesale_price_snapshot
            : $item->variant?->effectiveWholesalePrice();

        return (float) ($wholesale > 0
            ? $wholesale
            : ($item->wholesale_cost_snapshot ?? $item->variant?->average_cost ?? 0));
    }

    /** هامش الربح للبند: (سعر البيع − التكلفة) × الكمية، بلا سالب. */
    private function itemMargin(OrderItem $item, float $cost): float
    {
        return max(((float) $item->unit_price - $cost) * (float) $item->qty, 0);
    }

    private function accrueAffiliate(Order $order, OrderItem $item): void
    {
        $cost = $this->itemCost($item);
        $margin = $this->itemMargin($item, $cost);
        $rule = $this->resolveRule('affiliate', [
            'user_id' => $order->affiliate_id, 'product_id' => $item->variant?->product_id,
            'branch_id' => $order->branch_id,
        ]);

        [$amount, $rate] = $this->computeAmount($rule, 'affiliate', $margin, 1.0);

        $this->createAccrual($order, $item, 'affiliate', $order->affiliate_id, $margin, $rate, $amount, $cost, $rule);
    }

    /** @return array{0: float, 1: float|null} */
    private function computeAmount(?CommissionRule $rule, string $earnerType, float $basis, float $defaultRate): array
    {
        if ($rule === null) {
            return [round($basis * $defaultRate, 2), $defaultRate];
        }
        if ($rule->method === 'fixed') {
            return [(float) $rule->amount, null];
        }
        // «هامش الربح» = الهامش كاملًا للمستفيد — لا نسبة منه (قرار المالك).
        if ($rule->method === 'margin') {
            return [round($basis, 2), 1.0];
        }
        $rate = (float) ($rule->rate ?? $defaultRate);

        return [round($basis * $rate, 2), $rate];
    }

    private function createAccrual(Order $order, OrderItem $item, string $type, int $earnerId, float $basis, ?float $rate, float $amount, ?float $cost, ?CommissionRule $rule): void
    {
        $entry = CommissionEntry::create([
            'earner_type' => $type,
            'earner_id' => $earnerId,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'entry_type' => 'accrual',
            'basis' => $basis,
            'rate' => $rate,
            'amount' => $amount,
            'wholesale_cost_snapshot' => $cost,
            'rule_id' => $rule?->id,
            'rule_snapshot' => $rule ? $rule->only(['id', 'method', 'rate', 'amount', 'priority', 'campaign', 'role']) : ['method' => $type === 'sales' ? 'percent' : 'margin', 'rate' => $rate, 'default' => true],
            'state' => 'pending',
            'created_by' => auth()->id(),
        ]);
        $this->logTransition($entry, null, 'pending', 'accrual');
        CommissionAccrued::dispatch($entry);
    }

    /** تفعيل الاستحقاق بعد التسوية (يُستدعى من 4.6 حصريًا). pending → eligible. */
    public function markEligibleForOrder(Order $order, string $settlementReference, ?User $actor = null): int
    {
        $count = 0;
        DB::transaction(function () use ($order, $settlementReference, $actor, &$count) {
            $entries = CommissionEntry::where('order_id', $order->id)->where('state', 'pending')->get();
            foreach ($entries as $entry) {
                $entry->update(['settlement_reference' => $settlementReference]);
                $this->transition($entry, 'eligible', $actor, $settlementReference);
                $count++;
            }
        });

        return $count;
    }

    /** اعتماد بنود مستحقّة (دفعة). eligible → approved. منع الاعتماد المزدوج. */
    public function approve(array $entryIds, User $actor): int
    {
        $count = 0;
        DB::transaction(function () use ($entryIds, $actor, &$count) {
            $entries = CommissionEntry::whereIn('id', $entryIds)->where('state', 'eligible')->lockForUpdate()->get();
            foreach ($entries as $entry) {
                $this->transition($entry, 'approved', $actor);
                $count++;
            }
        });

        return $count;
    }

    /**
     * تعليم بنودٍ «مدفوعة» يدويًّا — **بلا سندٍ ولا قيدٍ ولا أثرٍ على الأرصدة**.
     *
     * ## لماذا هذا لا يمسّ المحاسبة
     *
     * الصرف الفعليّ يقع في `payAmount`: سندٌ يخرج به المال من الخزينة ويُرحَّل
     * في الدفتر. والدفعة **مبلغٌ على الحساب** لا تُقابَل ببنودٍ بعينها، فتبقى
     * البنود `eligible` بعدها — ويبقى المستخدم لا يعرف أيّ بندٍ غطّاه المال.
     *
     * وهذه العملية تسدّ ذلك الفراغ وحده: تُعلّم البنود التي غطّاها سندٌ صُرف
     * سابقًا. ولا يتحرّك بها رقمٌ ماليّ لأن `earned` يجمع `eligible` و`approved`
     * و`paid` سواءً، و«المدفوع» و«المتبقّي» يُقرآن من السندات لا من حالة البند.
     * فالمتغيّر الوحيد هو **وسمُ البند** — ومعه بطاقةُ «المستحقّة» التي تعدّ
     * البنود غير الموسومة.
     *
     * ولا يُصرف بها مال: من أراد الصرف فمن نموذج الدفع، وهذا للمطابقة فقط.
     *
     * @param  array<int, int|string>  $entryIds
     * @return array{count: int, total: float}
     */
    public function markSettledManually(array $entryIds, int $earnerId, string $earnerType, ?User $actor, ?string $note = null): array
    {
        return DB::transaction(function () use ($entryIds, $earnerId, $earnerType, $actor, $note) {
            // المستفيد في الشرط لا في الواجهة وحدها: معرّفٌ مُرسَل من متصفّح
            // يُصدَّق، وبدونه عُلِّم بندُ مسوّقٍ آخر من كشف هذا.
            $entries = CommissionEntry::whereIn('id', $entryIds)
                ->where('earner_id', $earnerId)->where('earner_type', $earnerType)
                ->whereIn('state', ['eligible', 'approved'])
                ->lockForUpdate()->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages([
                    'entries' => __('لم يُحدَّد بندٌ قابل للتعليم — اختر بنودًا مستحقّة أو معتمدة.'),
                ]);
            }

            // عمود المرجع ٨٠ حرفًا: ملاحظةٌ أطول كانت تُقطع في قاعدة البيانات
            // أو تُسقط الحفظ، فتضيع التسوية كلّها من أجل نصٍّ زائد.
            $reference = mb_substr($note ? 'manual: '.$note : 'manual', 0, 80);

            foreach ($entries as $entry) {
                $this->transition($entry, 'paid', $actor, $reference);
            }

            return [
                'count' => $entries->count(),
                'total' => round((float) $entries->sum('amount'), 2),
            ];
        });
    }

    /**
     * صرف دفعة لبنود معتمدة لمستفيد واحد. approved → paid. منع الدفع المزدوج (قيد فريد).
     */
    public function payout(User $actor, int $earnerId, string $earnerType, array $entryIds, ?string $reference = null): CommissionPayout
    {
        return DB::transaction(function () use ($actor, $earnerId, $earnerType, $entryIds, $reference) {
            $entries = CommissionEntry::whereIn('id', $entryIds)
                ->where('earner_id', $earnerId)->where('earner_type', $earnerType)
                ->where('state', 'approved')->lockForUpdate()->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['entries' => __('لا توجد بنود معتمدة قابلة للصرف.')]);
            }

            $payout = CommissionPayout::create([
                'earner_id' => $earnerId,
                'earner_type' => $earnerType,
                'total' => 0,
                'reference' => $reference,
                'status' => 'paid',
                'created_by' => $actor->id,
                'paid_at' => now(),
            ]);

            $total = 0;
            foreach ($entries as $entry) {
                $payout->entries()->create(['commission_entry_id' => $entry->id, 'amount' => $entry->amount]);
                $this->transition($entry, 'paid', $actor, $payout->uuid);
                $total += (float) $entry->amount;
            }
            $payout->update(['total' => round($total, 2)]);

            return $payout->fresh('entries');
        });
    }

    // ---- جاهزية المرتجعات (المتطلّب 7) — لا مسّ للتاريخ، حركات جديدة فقط ----

    /** تعديل تناسبي لعمولة بند عند إرجاع جزئي (يُنشئ حركة adjustment سالبة). */
    public function adjustForReturn(OrderItem $item, float $returnedQty, ?User $actor = null): void
    {
        $originalQty = (float) $item->qty;
        if ($originalQty <= 0 || $returnedQty <= 0) {
            return;
        }
        $proportion = min($returnedQty / $originalQty, 1.0);

        $accruals = CommissionEntry::where('order_item_id', $item->id)
            ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled'])->get();

        DB::transaction(function () use ($accruals, $proportion, $actor) {
            foreach ($accruals as $original) {
                $delta = -round((float) $original->amount * $proportion, 2);
                if (abs($delta) < 0.01) {
                    continue;
                }
                $adjustment = CommissionEntry::create([
                    'earner_type' => $original->earner_type,
                    'earner_id' => $original->earner_id,
                    'order_id' => $original->order_id,
                    'order_item_id' => $original->order_item_id,
                    'variant_id' => $original->variant_id,
                    'entry_type' => 'adjustment',
                    'basis' => -round((float) $original->basis * $proportion, 2),
                    'rate' => $original->rate,
                    'amount' => $delta,
                    'rule_id' => $original->rule_id,
                    'rule_snapshot' => $original->rule_snapshot,
                    'adjusts_entry_id' => $original->id,
                    // مدفوع → استرداد كـeligible سالب؛ غير مدفوع → نفس حالة الأصل.
                    'state' => $original->state === 'paid' ? 'eligible' : $original->state,
                    'created_by' => $actor?->id,
                ]);
                $this->logTransition($adjustment, null, $adjustment->state, 'adjustment');
                CommissionAdjusted::dispatch($adjustment);
            }
        });
    }

    /**
     * تصحيح عمولة بندٍ حُسبت على لقطة جملةٍ صفر.
     *
     * البنود القديمة على منتجٍ ذي مقاسات جُمّدت لقطتها صفرًا (عمود المتغيّر كان
     * فارغًا)، فهبط أساس العمولة إلى **التكلفة** بدل سعر الجملة — والتكلفة
     * أدنى، فالهامش أوسع والعمولة أعلى مما تستحقّ.
     *
     * والتصحيح **على الحركة نفسها** بقرار المالك: كشف المسوّق يجب أن يُقرأ
     * كسطرٍ واحد صحيح لكل بند، لا استحقاقًا خاطئًا يليه تعديلٌ يُصحّحه — فذلك
     * يُربك من يقرأ حسابه ويجعل الرقم الظاهر غير الرقم المستحقّ.
     *
     * والأثر لا يضيع: كل تصحيحٍ يُدوَّن في `commission_transitions` بقيمته
     * السابقة والجديدة، فيبقى السؤال «ماذا كان؟ ومتى تغيّر؟» مُجابًا خارج
     * الكشف.
     *
     * وحدّان لا يُتجاوزان:
     * - **الحركة المدفوعة لا تُمسّ** — سند الصرف يحمل مبلغها، وتغييرُه في
     *   الدفتر وحده يجعل الحساب يخالف ما خرج من الخزينة.
     * - **الحركة ذات التعديل السابق (مرتجع) تُترك** — تعديل المرتجع حُسب نسبةً
     *   من مبلغٍ خاطئ.
     *
     * ويُصحَّح **المسوّق وحده**: عمولة موظف المبيعات أساسها قيمة المبيعات لا
     * الهامش، فلم تمسّها اللقطة أصلًا.
     *
     * @return array<int, array{entry: CommissionEntry, was: float, now: float|null, delta: float, skipped?: string}>
     */
    public function correctWholesaleSnapshot(OrderItem $item, ?User $actor = null, bool $apply = true): array
    {
        return $this->recomputeItemCommissions(
            $item,
            $item->variant?->effectiveWholesalePrice() ?? 0.0,
            $actor,
            $apply,
        );
    }

    /**
     * إعادة احتساب عمولات بندٍ على سعر شراءٍ معطى.
     *
     * النواة المشتركة بين تصحيحين: تصحيح لقطة الجملة الصفرية، وإعادة التسعير
     * على قائمة أسعار تاجرٍ أُسندت بعد البيع. كلاهما يسأل السؤال نفسه — «بكم
     * اشترى هذا المشتري فعلًا؟» — ويختلفان في مصدر الجواب وحده.
     *
     * @param  float  $cost  سعر شراء المستفيد: سعر قائمته أو سعر الجملة
     * @param  int|null  $earnerId  حصرُ التصحيح بمستفيدٍ واحد، أو `null` للجميع
     * @return array<int, array{entry: CommissionEntry, was: float, now: float|null, delta: float, skipped?: string}>
     */
    public function recomputeItemCommissions(
        OrderItem $item,
        float $cost,
        ?User $actor = null,
        bool $apply = true,
        ?int $earnerId = null,
    ): array {
        // لا سعر شراءٍ معروف ⇒ لا مرجع نصحّح إليه، فتُترك كما هي.
        if ($cost <= 0) {
            return [];
        }

        $correctCost = $cost;
        $margin = $this->itemMargin($item, $correctCost);

        // القاعدة تُقرأ من `rule_snapshot` المحفوظة لا من القاعدة الحيّة: قاعدةٌ
        // تغيّرت بعد البيع لا تحكم عمولةً استُحقّت قبلها.
        $accruals = CommissionEntry::query()
            ->where('order_item_id', $item->id)
            ->where('earner_type', 'affiliate')
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->when($earnerId, fn ($q) => $q->where('earner_id', $earnerId))
            ->get();

        $changes = [];

        foreach ($accruals as $original) {
            $method = $original->rule_snapshot['method'] ?? 'margin';

            // العمولة الثابتة لا تتعلّق بالهامش أصلًا، فلا شيء يُصحَّح فيها.
            if ($method === 'fixed') {
                continue;
            }

            // المدفوعة لا تُمسّ: سند الصرف يحمل مبلغها، وتغييرُه في الدفتر
            // وحده يجعل الحساب يخالف ما خرج من الخزينة فعلًا.
            if ($original->state === 'paid') {
                $changes[] = ['entry' => $original, 'was' => (float) $original->amount, 'now' => null, 'delta' => 0.0, 'skipped' => 'paid'];

                continue;
            }

            $priorAdjustments = CommissionEntry::where('adjusts_entry_id', $original->id)->get();

            // تصحيحاتُ هذا الأمر نفسه من تشغيلٍ سابق (حين كان يكتب حركاتِ
            // تعديل): تُحذف ويُعاد الحساب على الأصل — وإلّا خُصم الفارق مرّتين.
            $stale = $priorAdjustments->filter(
                fn (CommissionEntry $a) => ($a->rule_snapshot['correction'] ?? null) === self::WHOLESALE_CORRECTION
            );

            // عليها تعديلٌ آخر (مرتجع مثلًا) ⇒ تُترك للمراجعة اليدوية. تعديل
            // المرتجع حُسب نسبةً من مبلغٍ خاطئ، وتصحيحُه آليًّا هنا يحتاج
            // افتراضًا عن ترتيب الحركات لا يصحّ أن يُتَّخذ بلا إنسان.
            if ($priorAdjustments->count() > $stale->count()) {
                $changes[] = ['entry' => $original, 'was' => (float) $original->amount, 'now' => null, 'delta' => 0.0, 'skipped' => 'has_prior_adjustment'];

                continue;
            }

            $rate = $method === 'margin' ? 1.0 : (float) ($original->rate ?? 1.0);
            $correctAmount = round($margin * $rate, 2);
            $delta = round($correctAmount - (float) $original->amount, 2);

            // لا فارق ولا تصحيحاتٍ قديمة تُنظَّف ⇒ لا شيء يُفعل. وبهذا يكون
            // تشغيل الأمر مرارًا آمنًا بلا وسمٍ إضافي: الحركة المصحَّحة تساوي
            // الصحيح فلا تُمسّ ثانيةً.
            if (abs($delta) < 0.01 && $stale->isEmpty()) {
                continue;
            }

            $changes[] = [
                'entry' => $original,
                'was' => (float) $original->amount,
                'now' => $correctAmount,
                'delta' => $delta,
                'stale' => $stale,
                'basis' => round($margin, 2),
            ];
        }

        $writable = array_values(array_filter($changes, fn (array $c) => ! isset($c['skipped'])));

        if ($apply && $writable !== []) {
            $this->writeWholesaleCorrections($item, $margin, $correctCost, $writable, $actor);
        }

        return $changes;
    }

    /**
     * تصحيح الحركات في مكانها ولقطة البند، في معاملةٍ واحدة.
     *
     * اللقطة تُصحَّح مع الحركة لا بعدها: لقطةٌ مصحَّحة بلا حركةٍ تجعل الرصيد
     * يخالف الدفتر، وحركةٌ بلا لقطةٍ مصحَّحة تُبقي أيّ إعادة احتسابٍ لاحقة
     * تنتج الخطأ نفسه.
     *
     * @param  array<int, array{entry: CommissionEntry, was: float, now: float, delta: float, stale: mixed, basis: float}>  $changes
     */
    /**
     * تبديل صنف حركات عمولة مسوّقٍ واحد من متغيّرٍ إلى آخر، وإعادة الاحتساب عليه.
     *
     * ## ما يُمَسّ وما لا يُمَسّ
     *
     * **الكشف وحده.** تُكتب `commission_entries` لا غير: الفاتورة وبنودها
     * والمخزون والإيراد وتكلفة المبيعات وقيود اليومية تبقى كما هي حرفًا بحرف.
     * فالبضاعة خرجت على الصنف القديم فعلًا، وإعادةُ كتابة ذلك تجعل المخزون
     * مخصومًا من صنفٍ والفاتورة على صنفٍ آخر.
     *
     * ## والصفر يُرفض إلا بقرارٍ صريح
     *
     * سعر جملةٍ صفر في هذا النظام يعني **«غير معروف»** لا «مجّاني»: أساس عمولة
     * المسوّق هو الهامش، فجملةٌ صفر تجعل الهامش سعرَ البيع كاملًا وتُضخّم
     * المستحقّ. ولهذا يُرفض افتراضًا — وهو نفس حارس `recomputeItemCommissions`.
     *
     * ويُقبل بـ`$allowZeroCost` **للصنف المُمرَّر في هذا الاستدعاء وحده**: لا
     * إعداد يُغيَّر، ولا صنف آخر يتأثّر، ولا يبقى القبول بعد انتهاء التشغيل.
     *
     * ## وحدود لا تُتجاوز
     *
     * - **المدفوعة يُبدَّل وسمُها ولا يُمَسّ مبلغها**: سند الصرف يحمل ما خرج من
     *   الخزينة، وتغييرُ الرقم في الدفتر وحده يجعل الحساب يخالف الخزينة. ويبقى
     *   الاسم موحَّدًا في الكشف لأن الوسم لا يحرّك مالًا.
     * - **ذاتُ التعديل السابق (مرتجع) تُترك للمراجعة اليدوية** — تعديل المرتجع
     *   حُسب نسبةً من مبلغٍ سابق، وتصحيحُه آليًّا يحتاج افتراضًا عن الترتيب.
     * - **العمولة الثابتة يُبدَّل وسمُها ولا يُعاد احتسابها** — لا تتعلّق بالهامش.
     *
     * وكل تغيير يُدوَّن في `commission_transitions` بقيمته السابقة والجديدة.
     *
     * @param  array<int, int>  $fromVariantIds
     * @return array<int, array<string, mixed>>
     */
    public function swapEntryVariant(
        User $earner,
        array $fromVariantIds,
        ProductVariant $to,
        ?User $actor = null,
        bool $apply = false,
        bool $allowZeroCost = false,
    ): array {
        $cost = round($to->effectiveWholesalePrice(), 2);

        /*
            الصفر يُرفض افتراضًا ويُقبل بقرارٍ صريح.

            في هذا النظام سعر جملةٍ صفر يعني «غير معروف» لا «مجّاني»، وأساس
            عمولة المسوّق هو الهامش — فصفرٌ يجعل الهامش سعرَ البيع كاملًا. وهو
            في الغالب كرتُ صنفٍ لم يُملأ، لا صنفٌ بلا كلفة.

            لكنّ الصنف قد يكون فعلًا بلا كلفة شراء (هديّة، أو عيّنة، أو ما يُصنّع
            داخليًّا بلا تسعير شراء). فالقرار لصاحب النظام، ويُطلب صراحةً
            بـ`$allowZeroCost` كي لا يقع بسهوٍ في تشغيلٍ عابر.
        */
        if ($cost <= 0 && ! $allowZeroCost) {
            throw ValidationException::withMessages(['wholesale' => __(
                'سعر جملة «:name» صفر — والصفر هنا يعني «غير معروف» لا «مجّاني»، '
                .'فيصير الهامش سعرَ البيع كاملًا ويتضخّم المستحقّ. صحّح كرت الصنف ثم أعد التشغيل، '
                .'أو أضف --allow-zero-wholesale إن كان الصنف بلا كلفة شراء فعلًا.',
                ['name' => $to->product?->name ?? $to->sku],
            )]);
        }

        $cost = max($cost, 0.0);

        $entries = CommissionEntry::query()
            ->where('earner_id', $earner->id)
            ->where('earner_type', 'affiliate')
            ->where('entry_type', 'accrual')
            ->whereIn('variant_id', $fromVariantIds)
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->with(['orderItem', 'order:id,number,created_at', 'variant.product:id,name'])
            ->orderBy('id')
            ->get();

        $changes = [];

        foreach ($entries as $entry) {
            $change = $this->planVariantSwap($entry, $to, $cost);

            // في إعادة الاحتساب على الصنف نفسه تُسقَط الحركات التي لا شيء
            // فيها يتغيّر: كتابتُها تُحدث سطرًا في سجلّ التحوّلات بلا تحوّل،
            // وعرضُها يُغرق الجدول بأصفارٍ تُخفي ما تغيّر فعلًا.
            if ($this->isNoopSwap($entry, $to, $cost, $change)) {
                continue;
            }

            $changes[] = $change;
        }

        if ($apply && $changes !== []) {
            $this->writeVariantSwaps($changes, $to, $cost, $actor);
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function isNoopSwap(CommissionEntry $entry, ProductVariant $to, float $cost, array $change): bool
    {
        return $entry->variant_id === $to->id
            && abs((float) $change['delta']) < 0.01
            && abs((float) $entry->wholesale_cost_snapshot - $cost) < 0.01;
    }

    /**
     * ماذا يقع على حركةٍ واحدة — قرارًا قبل الكتابة.
     *
     * يُفصل التخطيط عن الكتابة كي يكون العرض التجريبي هو نفسه ما سيُنفَّذ، لا
     * حسابًا ثانيًا قد يختلف عنه.
     *
     * @return array<string, mixed>
     */
    private function planVariantSwap(CommissionEntry $entry, ProductVariant $to, float $cost): array
    {
        $base = [
            'entry' => $entry,
            'was' => (float) $entry->amount,
            'now' => (float) $entry->amount,
            'delta' => 0.0,
            'relabel_only' => true,
        ];

        if ($entry->state === 'paid') {
            return $base + ['reason' => 'paid'];
        }

        if (($entry->rule_snapshot['method'] ?? 'margin') === 'fixed') {
            return $base + ['reason' => 'fixed'];
        }

        if (CommissionEntry::where('adjusts_entry_id', $entry->id)->exists()) {
            return $base + ['reason' => 'has_prior_adjustment'];
        }

        $item = $entry->orderItem;

        if (! $item) {
            return $base + ['reason' => 'no_order_item'];
        }

        $margin = $this->itemMargin($item, $cost);
        $rate = (float) ($entry->rate ?? 1.0);
        $amount = round(($entry->rule_snapshot['method'] ?? 'margin') === 'margin' ? $margin : $margin * $rate, 2);

        return [
            'entry' => $entry,
            'was' => (float) $entry->amount,
            'now' => $amount,
            'delta' => round($amount - (float) $entry->amount, 2),
            'basis' => round($margin, 2),
            'relabel_only' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function writeVariantSwaps(array $changes, ProductVariant $to, float $cost, ?User $actor): void
    {
        DB::transaction(function () use ($changes, $to, $cost, $actor) {
            foreach ($changes as $change) {
                /** @var CommissionEntry $entry */
                $entry = $change['entry'];

                // الوسم وحده للمدفوعة والثابتة وذات التعديل السابق: الاسم يوحَّد
                // في الكشف ولا يتحرّك مالٌ ولا لقطةُ كلفةٍ حُسب عليها المبلغ.
                if ($change['relabel_only']) {
                    CommissionEntry::whereKey($entry->id)->toBase()->update([
                        'variant_id' => $to->id,
                        'updated_at' => now(),
                    ]);

                    $this->logVariantSwap($entry, $to, null, null, $change['reason'] ?? null, $actor);

                    continue;
                }

                // `basis` و`amount` محروسان بحارس عدم التعديل في النموذج،
                // ويُتجاوزان هنا بقرارٍ صريح بالكتابة عبر الاستعلام — كما في
                // تصحيح لقطة الجملة تمامًا، والأثر مُدوَّن.
                CommissionEntry::whereKey($entry->id)->toBase()->update([
                    'variant_id' => $to->id,
                    'basis' => $change['basis'],
                    'amount' => $change['now'],
                    'wholesale_cost_snapshot' => $cost,
                    'updated_at' => now(),
                ]);

                $this->logVariantSwap($entry, $to, $change['was'], $change['now'], null, $actor);

                CommissionAdjusted::dispatch($entry->refresh());
            }
        });
    }

    private function logVariantSwap(
        CommissionEntry $entry,
        ProductVariant $to,
        ?float $was,
        ?float $now,
        ?string $reason,
        ?User $actor,
    ): void {
        $label = $to->product?->name ?? $to->sku;

        $note = $was === null
            ? 'تبديل الصنف إلى «'.$label.'» — الوسم فقط ('.($reason ?? 'relabel').')'
            : 'تبديل الصنف إلى «'.$label.'» وإعادة الاحتساب: '
                .number_format($was, 2).' ← '.number_format((float) $now, 2);

        CommissionTransition::create([
            'commission_entry_id' => $entry->id,
            'from_state' => $entry->state,
            'to_state' => $entry->state,   // الحالة لم تتغيّر — الصنف والمبلغ
            'actor_id' => $actor?->id ?? auth()->id(),
            'reference' => 'variant_swap',
            'note' => $note,
        ]);
    }

    /**
     * إعادة تسعير عمولات مسوّقٍ واحد على قائمة أسعاره.
     *
     * قائمة التاجر تُسند بعد أن يكون قد باع، فعمولاته القديمة محسوبةٌ على سعر
     * الجملة العام لا على ما يشتري به فعلًا. والفرق حقيقي: من يشتري بـ٦٥ لا
     * يُحسب ربحه كأنه اشترى بـ٨٠.
     *
     * ويُحصر بمستفيدٍ واحد لأن القائمة شخصيّة: الطلب الواحد قد يحمل عمولةً
     * لغيره لا تخضع لقائمته.
     *
     * الصنف غير المسعَّر في قائمته يعود إلى سعر جملته الفعّال — كما يفعل حسم
     * السعر في الطلب تمامًا، فلا يختلف الكشف عن المصدر.
     *
     * @return array<int, array{item: OrderItem, cost: float} & array<string, mixed>>
     */
    public function repriceForEarner(User $earner, ?User $actor = null, bool $apply = true): array
    {
        $list = $this->prices->listFor($earner);

        if ($list === null) {
            return [];
        }

        $items = OrderItem::with(['variant.product:id,wholesale_price', 'order:id,number,created_at'])
            ->whereHas('commissionEntries', fn ($q) => $q
                ->where('earner_type', 'affiliate')
                ->where('earner_id', $earner->id)
                ->where('entry_type', 'accrual')
                ->whereNotIn('state', ['reversed', 'cancelled']))
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        // أسعار القائمة لكل المتغيّرات دفعةً واحدة — لا استعلامًا لكل بند.
        $listPrices = $this->prices->pricesForList(
            $list,
            $items->pluck('variant_id')->filter()->unique()->values()->all(),
        );

        $changes = [];

        foreach ($items as $item) {
            $cost = (float) ($listPrices[$item->variant_id]
                ?? $item->variant?->effectiveWholesalePrice()
                ?? 0);

            foreach ($this->recomputeItemCommissions($item, $cost, $actor, $apply, $earner->id) as $change) {
                $changes[] = $change + ['item' => $item, 'cost' => $cost];
            }
        }

        return $changes;
    }

    private function writeWholesaleCorrections(OrderItem $item, float $margin, float $cost, array $changes, ?User $actor): void
    {
        DB::transaction(function () use ($item, $cost, $changes, $actor) {
            foreach ($changes as $change) {
                $original = $change['entry'];

                // حركات التعديل التي كتبها تشغيلٌ سابق لهذا الأمر تُحذف: صار
                // التصحيح على الأصل، فبقاؤها يخصم الفارق مرّتين.
                foreach ($change['stale'] as $obsolete) {
                    CommissionTransition::where('commission_entry_id', $obsolete->id)->delete();
                    $obsolete->forceDelete();
                }

                // الدفتر يمنع تعديل المبالغ عبر النموذج (حارس `updating`)،
                // ويُتجاوز هنا **بقرارٍ صريح** بالكتابة عبر الاستعلام: الكشف
                // يجب أن يُقرأ سطرًا واحدًا صحيحًا لكل بند. والأثر يُدوَّن في
                // `commission_transitions` بالقيمة السابقة والجديدة، فلا يضيع
                // جواب «ماذا كان؟ ومتى تغيّر؟».
                CommissionEntry::whereKey($original->id)->toBase()->update([
                    'basis' => $change['basis'],
                    'amount' => $change['now'],
                    'wholesale_cost_snapshot' => round($cost, 2),
                    'updated_at' => now(),
                ]);

                $this->logCorrection($original, $change['was'], $change['now'], $actor);

                CommissionAdjusted::dispatch($original->refresh());
            }

            $item->forceFill(['wholesale_price_snapshot' => round($cost, 2)])->save();
        });
    }

    /** تدوين التصحيح: القيمة السابقة والجديدة وسببهما. */
    private function logCorrection(CommissionEntry $entry, float $was, float $now, ?User $actor): void
    {
        CommissionTransition::create([
            'commission_entry_id' => $entry->id,
            'from_state' => $entry->state,
            'to_state' => $entry->state,   // الحالة لم تتغيّر — المبلغ وحده
            'actor_id' => $actor?->id ?? auth()->id(),
            'reference' => 'wholesale_snapshot_correction',
            'note' => 'تصحيح أساس العمولة إلى سعر الجملة: '
                .number_format($was, 2).' ← '.number_format($now, 2),
        ]);
    }

    /** عكس كامل لعمولات طلب (إرجاع كامل/إلغاء بعد الاستحقاق). */
    public function reverseForOrder(Order $order, ?User $actor = null): void
    {
        $accruals = CommissionEntry::where('order_id', $order->id)
            ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled'])->get();

        DB::transaction(function () use ($accruals, $actor) {
            foreach ($accruals as $original) {
                if ($original->state === 'paid') {
                    // استرداد كـeligible سالب؛ الأصل يبقى تاريخيًا مدفوعًا ثم يُعلَّم reversed.
                    $reversal = CommissionEntry::create([
                        'earner_type' => $original->earner_type,
                        'earner_id' => $original->earner_id,
                        'order_id' => $original->order_id,
                        'order_item_id' => $original->order_item_id,
                        'variant_id' => $original->variant_id,
                        'entry_type' => 'reversal',
                        'basis' => -(float) $original->basis,
                        'rate' => $original->rate,
                        'amount' => -(float) $original->amount,
                        'rule_id' => $original->rule_id,
                        'rule_snapshot' => $original->rule_snapshot,
                        'reverses_entry_id' => $original->id,
                        'state' => 'eligible',
                        'created_by' => $actor?->id,
                    ]);
                    $this->logTransition($reversal, null, 'eligible', 'reversal');
                    CommissionReversed::dispatch($reversal);
                }
                $this->transition($original, 'reversed', $actor, 'return');
            }
        });
    }

    /** إلغاء عمولات طلب قبل الاستحقاق (لم تُدفع). */
    public function cancelForOrder(Order $order, ?User $actor = null): void
    {
        $entries = CommissionEntry::where('order_id', $order->id)
            ->whereIn('state', ['pending', 'eligible', 'approved'])->get();
        DB::transaction(function () use ($entries, $actor) {
            foreach ($entries as $entry) {
                $this->transition($entry, 'cancelled', $actor);
            }
        });
    }

    // ---- دفع الأرباح بمبلغ حرّ عبر سند صرف مالي (ADR-012e) ----

    /**
     * دفع أرباح مستفيد بمبلغ حرّ (قد يقلّ/يزيد عن المستحق) من خزينة/بنك محدّد،
     * موثّقًا بسند صرف مالي (payment) **يُعتمد ويُرحّل فورًا** (قرار المالك): الصرف
     * يقع لحظة الضغط، فيخرج المبلغ من الخزينة ويُقيَّد مصروف العمولات مباشرةً بلا
     * انتظار اعتماد المالية. التصحيح لاحقًا يكون بعكس السند لا بحذفه (ADR-016).
     */
    public function payAmount(
        User $actor,
        int $earnerId,
        string $earnerType,
        float $amount,
        int $treasuryId,
        int $counterAccountId,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?string $reference = null,
        ?string $notes = null,
    ): CommissionPayout {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون أكبر من صفر.')]);
        }

        $earner = User::findOrFail($earnerId);
        $label = $earnerType === 'affiliate' ? __('أرباح مسوّق') : __('عمولة موظف مبيعات');
        $period = $periodStart ? ' ('.$periodStart.' → '.$periodEnd.')' : '';

        return DB::transaction(function () use ($actor, $earner, $earnerId, $earnerType, $amount, $treasuryId, $counterAccountId, $periodStart, $periodEnd, $reference, $notes, $label, $period) {
            $voucher = app(VoucherService::class)->create('payment', [
                'voucher_date' => now()->toDateString(),
                'treasury_id' => $treasuryId,
                'counter_account_id' => $counterAccountId,
                'employee_id' => $earnerId,
                'party_name' => $earner->name,
                'amount' => round($amount, 2),
                'reference' => $reference,
                'category' => 'commission_payout',
                'description' => $label.' — '.$earner->name.$period,
                'notes' => $notes,
            ]);

            // اعتماد وترحيل فوريان: مدين مصروف العمولات / دائن الخزينة.
            app(VoucherService::class)->approve($voucher, $actor);
            app(VoucherService::class)->post($voucher, $actor);

            return CommissionPayout::create([
                'earner_id' => $earnerId,
                'earner_type' => $earnerType,
                'treasury_id' => $treasuryId,
                'financial_voucher_id' => $voucher->id,
                'total' => round($amount, 2),
                'reference' => $reference ?: $voucher->number,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'paid',
                'notes' => $notes,
                'created_by' => $actor->id,
                'paid_at' => now(),
            ]);
        });
    }

    /**
     * ما على الشركة للمستفيدين جميعًا الآن — **بعد طرح ما صُرف**.
     *
     * ## لماذا لا يكفي جمع الحالات
     *
     * صرفُ دفعةٍ عبر `payAmount` يُنشئ سندًا وسجلَّ دفعة، ولا يُحوّل بنود العمولة
     * إلى `paid`: الدفعة **مبلغٌ على الحساب** قد يغطّي بعض البنود أو يزيد عليها،
     * فلا تُقابَل ببنودٍ بعينها. فبقيت البنود `eligible` بعد الصرف — وجمعُ الحالات
     * وحده يُبقي الرقم كما كان وكأن المال لم يخرج.
     *
     * وهذا هو تعريف `balance()` نفسه مطبَّقًا على الجميع: المستحقّ ناقص ما صُرف
     * وما هو في طريقه. وسندٌ ملغًى أو معكوس لا يُطرح — ماله عاد.
     */
    public function outstandingTotal(): float
    {
        $earned = (float) CommissionEntry::whereIn('state', ['eligible', 'approved', 'paid'])->sum('amount');

        // بلا سند = دفعة قديمة من النظام السابق ⇒ تُعتبر مصروفة.
        //
        // والمبلغ يُقرأ من السند حيث وُجد: عمود `total` نسخةٌ تُكتب لحظة الإنشاء،
        // وتعديلُ السند لاحقًا يجعلها قديمة — فيُطرح رقمٌ لم يعد قائمًا.
        $settled = (float) CommissionPayout::query()
            ->leftJoin('financial_vouchers as v', 'v.id', '=', 'commission_payouts.financial_voucher_id')
            ->where(fn ($q) => $q->whereNull('v.id')->orWhereIn('v.status', ['posted', 'draft', 'approved']))
            ->sum(DB::raw('COALESCE(v.amount, commission_payouts.total)'));

        return round($earned - $settled, 2);
    }

    /**
     * رصيد المستفيد: المستحق (كل الأرباح المؤهّلة صافيةً) − المدفوع فعليًا (سند مُرحّل)
     * − قيد الاعتماد (سند مسودّة/معتمد لم يُرحّل بعد). سندات ملغاة/معكوسة لا تُحتسب.
     *
     * @return array{earned: float, paid: float, pending_payout: float, outstanding: float}
     */
    public function balance(int $earnerId, string $earnerType): array
    {
        $earned = (float) CommissionEntry::where('earner_id', $earnerId)->where('earner_type', $earnerType)
            ->whereIn('state', ['eligible', 'approved', 'paid'])->sum('amount');

        // `amount` في التحديد: المبلغ يُقرأ من السند لا من نسخته المحفوظة —
        // تعديلُ السند يجعل النسخة قديمة فيُطرح من المستحقّ رقمٌ لم يُصرف.
        $payouts = CommissionPayout::where('earner_id', $earnerId)->where('earner_type', $earnerType)
            ->with('voucher:id,status,amount')->get();

        // بلا سند = مدفوعة قديمة (النظام السابق) ⇒ تُعتبر مُرحّلة.
        $posted = fn ($p) => $p->voucher === null || $p->voucher->status === 'posted';
        $draft = fn ($p) => $p->voucher !== null && in_array($p->voucher->status, ['draft', 'approved'], true);

        $paid = round((float) $payouts->filter($posted)->sum(fn ($p) => $p->settledAmount()), 2);
        $pending = round((float) $payouts->filter($draft)->sum(fn ($p) => $p->settledAmount()), 2);
        $earned = round($earned, 2);

        return [
            'earned' => $earned,
            'paid' => $paid,
            'pending_payout' => $pending,
            'outstanding' => round($earned - $paid - $pending, 2),
        ];
    }

    // ---- الأرصدة (مشتقّة من الدفتر — لا عمود رصيد) ----

    /** @return array<string, float> */
    public function statement(int $earnerId, string $earnerType): array
    {
        $sum = fn (string $state) => (float) CommissionEntry::where('earner_id', $earnerId)
            ->where('earner_type', $earnerType)->where('state', $state)->sum('amount');

        return [
            'pending' => round($sum('pending'), 2),
            'eligible' => round($sum('eligible'), 2),
            'approved' => round($sum('approved'), 2),
            // المدفوع = الدفعات ذات سند مُرحّل فقط؛ المسودّة/المعتمدة لم يخرج مالها بعد.
            'paid' => $this->balance($earnerId, $earnerType)['paid'],
        ];
    }

    // ---- آلة الحالة ----

    private function transition(CommissionEntry $entry, string $to, ?User $actor, ?string $reference = null): void
    {
        $from = $entry->state;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['state' => __("انتقال غير مسموح: {$from} → {$to}.")]);
        }
        $entry->update(['state' => $to]);
        $this->logTransition($entry, $from, $to, $reference, $actor);
    }

    private function logTransition(CommissionEntry $entry, ?string $from, string $to, ?string $reference, ?User $actor = null): void
    {
        CommissionTransition::create([
            'commission_entry_id' => $entry->id,
            'from_state' => $from,
            'to_state' => $to,
            'actor_id' => $actor?->id ?? auth()->id(),
            'reference' => $reference,
        ]);
    }
}
