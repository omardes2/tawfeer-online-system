<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Accounting\Services\VoucherService;
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

    /** الانتقالات المسموحة لآلة الحالة. */
    private const TRANSITIONS = [
        'pending' => ['eligible', 'cancelled', 'reversed'],
        'eligible' => ['approved', 'cancelled', 'reversed'],
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
     * والتصحيح **بحركةٍ جديدة لا بتعديل القديمة**: الدفتر يمنع تغيير المبالغ
     * بعد الإنشاء (حارس في `CommissionEntry`)، وهذا مقصود — دفترٌ يُعدَّل بأثرٍ
     * رجعيّ لا يُسأل عمّا جرى. فتُضاف حركة `adjustment` بالفارق وتُنسب إلى
     * أصلها، فيبقى الأصل والتصحيح ظاهرين معًا.
     *
     * ويُصحَّح **المسوّق وحده**: عمولة موظف المبيعات أساسها قيمة المبيعات لا
     * الهامش، فلم تمسّها اللقطة أصلًا.
     *
     * @return array<int, array{entry: CommissionEntry, was: float, now: float, delta: float}>
     */
    public function correctWholesaleSnapshot(OrderItem $item, ?User $actor = null, bool $apply = true): array
    {
        $correctCost = $item->variant?->effectiveWholesalePrice() ?? 0.0;

        // لا سعر جملةٍ فعّال ⇒ لا مرجع نصحّح إليه، فتُترك كما هي.
        if ($correctCost <= 0) {
            return [];
        }

        $margin = $this->itemMargin($item, $correctCost);

        // القاعدة تُقرأ من `rule_snapshot` المحفوظة لا من القاعدة الحيّة: قاعدةٌ
        // تغيّرت بعد البيع لا تحكم عمولةً استُحقّت قبلها.
        $accruals = CommissionEntry::query()
            ->where('order_item_id', $item->id)
            ->where('earner_type', 'affiliate')
            ->where('entry_type', 'accrual')
            ->whereNotIn('state', ['reversed', 'cancelled'])
            ->get();

        $changes = [];

        foreach ($accruals as $original) {
            $method = $original->rule_snapshot['method'] ?? 'margin';

            // العمولة الثابتة لا تتعلّق بالهامش أصلًا، فلا شيء يُصحَّح فيها.
            if ($method === 'fixed') {
                continue;
            }

            $priorAdjustments = CommissionEntry::where('adjusts_entry_id', $original->id)->get();

            // صُحّحت من قبل ⇒ تُترك. الأمر يُشغَّل أكثر من مرّة بطبيعته (عرضٌ
            // ثم تنفيذ، ثم تحقّق)، وتصحيحٌ يتكرّر يخصم الفارق مرّتين.
            if ($priorAdjustments->contains(fn (CommissionEntry $a) => ($a->rule_snapshot['correction'] ?? null) === self::WHOLESALE_CORRECTION)) {
                continue;
            }

            // عليها تعديلٌ آخر (مرتجع مثلًا) ⇒ تُترك للمراجعة اليدوية. تعديل
            // المرتجع حُسب نسبةً من مبلغٍ خاطئ، وتصحيحُه آليًّا هنا يحتاج
            // افتراضًا عن ترتيب الحركات لا يصحّ أن يُتَّخذ بلا إنسان.
            if ($priorAdjustments->isNotEmpty()) {
                $changes[] = ['entry' => $original, 'was' => (float) $original->amount, 'now' => null, 'delta' => 0.0, 'skipped' => 'has_prior_adjustment'];

                continue;
            }

            $rate = $method === 'margin' ? 1.0 : (float) ($original->rate ?? 1.0);
            $correctAmount = round($margin * $rate, 2);
            $delta = round($correctAmount - (float) $original->amount, 2);

            if (abs($delta) < 0.01) {
                continue;
            }

            $changes[] = [
                'entry' => $original,
                'was' => (float) $original->amount,
                'now' => $correctAmount,
                'delta' => $delta,
            ];
        }

        $writable = array_values(array_filter($changes, fn (array $c) => ! isset($c['skipped'])));

        if ($apply && $writable !== []) {
            $this->writeWholesaleCorrections($item, $margin, $correctCost, $writable, $actor);
        }

        return $changes;
    }

    /**
     * كتابة التصحيحات ولقطة البند في معاملةٍ واحدة.
     *
     * اللقطة تُصحَّح مع الحركة لا بعدها: لقطةٌ مصحَّحة بلا حركةٍ تجعل الرصيد
     * يخالف الدفتر، وحركةٌ بلا لقطةٍ مصحَّحة تُبقي أيّ إعادة احتسابٍ لاحقة
     * تنتج الخطأ نفسه.
     *
     * @param  array<int, array{entry: CommissionEntry, was: float, now: float, delta: float}>  $changes
     */
    private function writeWholesaleCorrections(OrderItem $item, float $margin, float $cost, array $changes, ?User $actor): void
    {
        DB::transaction(function () use ($item, $margin, $cost, $changes, $actor) {
            foreach ($changes as $change) {
                $original = $change['entry'];

                $adjustment = CommissionEntry::create([
                    'earner_type' => $original->earner_type,
                    'earner_id' => $original->earner_id,
                    'order_id' => $original->order_id,
                    'order_item_id' => $original->order_item_id,
                    'variant_id' => $original->variant_id,
                    'entry_type' => 'adjustment',
                    'basis' => round($margin - (float) $original->basis, 2),
                    'rate' => $original->rate,
                    'amount' => $change['delta'],
                    'wholesale_cost_snapshot' => $cost,
                    'rule_id' => $original->rule_id,
                    // الوسم داخل اللقطة لا في عمودٍ جديد: به يُعرف التصحيح فلا
                    // يتكرّر، وبلا تغييرٍ في المخطّط.
                    'rule_snapshot' => ($original->rule_snapshot ?? []) + ['correction' => self::WHOLESALE_CORRECTION],
                    'adjusts_entry_id' => $original->id,
                    // مدفوع ⇒ استرداد كـ`eligible` سالب؛ وإلّا فحالة الأصل —
                    // كما في تصحيح المرتجع تمامًا.
                    'state' => $original->state === 'paid' ? 'eligible' : $original->state,
                    'created_by' => $actor?->id,
                ]);

                $this->logTransition($adjustment, null, $adjustment->state, 'wholesale_snapshot_correction');
                CommissionAdjusted::dispatch($adjustment);
            }

            $item->forceFill(['wholesale_price_snapshot' => round($cost, 2)])->save();
        });
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
     * رصيد المستفيد: المستحق (كل الأرباح المؤهّلة صافيةً) − المدفوع فعليًا (سند مُرحّل)
     * − قيد الاعتماد (سند مسودّة/معتمد لم يُرحّل بعد). سندات ملغاة/معكوسة لا تُحتسب.
     *
     * @return array{earned: float, paid: float, pending_payout: float, outstanding: float}
     */
    public function balance(int $earnerId, string $earnerType): array
    {
        $earned = (float) CommissionEntry::where('earner_id', $earnerId)->where('earner_type', $earnerType)
            ->whereIn('state', ['eligible', 'approved', 'paid'])->sum('amount');

        $payouts = CommissionPayout::where('earner_id', $earnerId)->where('earner_type', $earnerType)
            ->with('voucher:id,status')->get();

        // بلا سند = مدفوعة قديمة (النظام السابق) ⇒ تُعتبر مُرحّلة.
        $posted = fn ($p) => $p->voucher === null || $p->voucher->status === 'posted';
        $draft = fn ($p) => $p->voucher !== null && in_array($p->voucher->status, ['draft', 'approved'], true);

        $paid = round((float) $payouts->filter($posted)->sum('total'), 2);
        $pending = round((float) $payouts->filter($draft)->sum('total'), 2);
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
