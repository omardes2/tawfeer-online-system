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
        $basis = (float) $item->qty * (float) $item->unit_price - (float) $item->discount;
        $rule = $this->resolveRule('sales', [
            'user_id' => $order->assigned_to, 'product_id' => $item->variant?->product_id,
            'branch_id' => $order->branch_id,
        ]);

        [$amount, $rate] = $this->computeAmount($rule, 'sales', $basis, self::DEFAULT_SALES_RATE);

        $this->createAccrual($order, $item, 'sales', $order->assigned_to, $basis, $rate, $amount, null, $rule);
    }

    private function accrueAffiliate(Order $order, OrderItem $item): void
    {
        $cost = (float) ($item->wholesale_cost_snapshot ?? $item->variant?->average_cost ?? 0);
        $margin = ((float) $item->unit_price - $cost) * (float) $item->qty;
        $margin = max($margin, 0);
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
     * موثّقًا بسند صرف مالي (payment) بحالة مسودّة تعتمده وتُرحّله المالية.
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

            return CommissionPayout::create([
                'earner_id' => $earnerId,
                'earner_type' => $earnerType,
                'treasury_id' => $treasuryId,
                'financial_voucher_id' => $voucher->id,
                'total' => round($amount, 2),
                'reference' => $reference ?: $voucher->number,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => $actor->id,
                'paid_at' => null,
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
            'paid' => round((float) DB::table('commission_payouts')->where('earner_id', $earnerId)->where('earner_type', $earnerType)->sum('total'), 2),
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
