<?php

namespace App\Modules\Settlements\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Settlements\Models\DeliverySettlement;
use App\Modules\Settlements\Models\SettlementLine;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentFeeComponent;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك التسوية المالية مع مزوّد التوصيل (Phase 4.6 / ADR-041).
 *
 * يطابق بيان المزوّد بسجلّاتنا (COD/رسوم/خصومات/صافٍ)، يكشف التباين، ثم عند الترحيل:
 * **يُنشئ قيدًا محاسبيًا مزدوجًا عبر `AccountingService` القائم** (لا تكرار)، ويؤكّد تسوية
 * الطلبات واستحقاق العمولات (`markEligibleForOrder` — idempotent، مؤكِّد لما جرى عند الإغلاق).
 * لا حذف — عكس (ADR-016). كل خطوة داخل معاملة ذرّية.
 */
class SettlementService
{
    // رموز الحسابات (ChartOfAccounts).
    private const ACC_COD_CLEARING = '1050'; // ذمم شركات التوصيل

    private const ACC_DELIVERY_EXPENSE = '5020';

    private const ACC_DISCOUNTS = '5030';

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly CommissionService $commissions,
    ) {}

    /** إنشاء تسوية (مسودّة) ببيان المزوّد المُبلَّغ. */
    public function create(array $data, ?User $actor = null): DeliverySettlement
    {
        return DeliverySettlement::create([
            'number' => NumberGenerator::next('delivery_settlements', 'number', 'STL', (int) now()->year),
            'delivery_provider_id' => $data['delivery_provider_id'] ?? null,
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'status' => 'draft',
            'reported_cod_total' => $data['reported_cod_total'] ?? 0,
            'reported_fees_total' => $data['reported_fees_total'] ?? 0,
            'reported_deductions_total' => $data['reported_deductions_total'] ?? 0,
            'reported_net' => $data['reported_net'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id ?? auth()->id(),
        ]);
    }

    /**
     * إضافة سطور من شحنات مُغلقة (delivery_status=closed) — COD من الطلب، الرسوم من مكوّناتها.
     *
     * @param  array<int, int>  $shipmentIds
     */
    public function addClosedShipments(DeliverySettlement $settlement, array $shipmentIds, ?User $actor = null): DeliverySettlement
    {
        $this->assertDraft($settlement);

        DB::transaction(function () use ($settlement, $shipmentIds) {
            $shipments = Shipment::with('order')->whereIn('id', $shipmentIds)
                ->where('delivery_status', DeliveryStatus::CLOSED)->get();

            foreach ($shipments as $shipment) {
                if ($shipment->order === null) {
                    continue;
                }
                // تفادي التكرار داخل التسوية نفسها.
                if ($settlement->lines()->where('shipment_id', $shipment->id)->exists()) {
                    continue;
                }
                $cod = (float) $shipment->order->total;
                $fees = (float) ShipmentFeeComponent::where('shipment_id', $shipment->id)->sum('amount');
                $this->pushLine($settlement, $shipment->order_id, $shipment->id, $cod, $fees, 0);
            }
            $this->recomputeComputed($settlement);
        });

        return $settlement->fresh('lines');
    }

    /** سطر يدوي (طلب/شحنة اختياريان) — يدعم خصومات/تسويات عامّة (بديل مطالبات 4.5 المُلغاة). */
    public function addLine(DeliverySettlement $settlement, array $data, ?User $actor = null): SettlementLine
    {
        $this->assertDraft($settlement);

        return DB::transaction(function () use ($settlement, $data) {
            $line = $this->pushLine(
                $settlement,
                $data['order_id'] ?? null,
                $data['shipment_id'] ?? null,
                (float) ($data['cod_amount'] ?? 0),
                (float) ($data['fee_amount'] ?? 0),
                (float) ($data['deduction_amount'] ?? 0),
                isset($data['reported_cod']) ? (float) $data['reported_cod'] : null,
                $data['note'] ?? null,
            );
            $this->recomputeComputed($settlement);

            return $line;
        });
    }

    /** المطابقة: احتساب المحسوب، كشف التباين لكل سطر وإجماليًا. draft → reconciled. */
    public function reconcile(DeliverySettlement $settlement, ?User $actor = null): DeliverySettlement
    {
        $this->transition($settlement, 'reconciled', function () use ($settlement, $actor) {
            $settlement->load('lines');
            foreach ($settlement->lines as $line) {
                if ($line->reported_cod !== null) {
                    $variance = round((float) $line->reported_cod - (float) $line->cod_amount, 2);
                    $line->update(['variance' => $variance, 'matched' => abs($variance) < 0.01]);
                }
            }
            $this->recomputeComputed($settlement);
            $settlement->update([
                'variance' => round((float) $settlement->reported_net - (float) $settlement->computed_net, 2),
                'reconciled_by' => $actor?->id ?? auth()->id(),
                'reconciled_at' => now(),
            ]);
        });

        return $settlement;
    }

    /**
     * الترحيل المالي: قيد محاسبي مزدوج + تأكيد تسوية الطلبات واستحقاق العمولات. reconciled → posted.
     */
    public function post(DeliverySettlement $settlement, ?User $actor = null): DeliverySettlement
    {
        $settlement->loadMissing('lines.order');
        $this->recomputeComputed($settlement);

        if ((float) $settlement->computed_cod_total <= 0) {
            throw ValidationException::withMessages(['lines' => __('لا توجد مبالغ COD للترحيل.')]);
        }
        if ((float) $settlement->computed_net < 0) {
            throw ValidationException::withMessages(['net' => __('صافي التسوية سالب — يتطلّب معالجة يدوية.')]);
        }

        $this->transition($settlement, 'posted', function () use ($settlement, $actor) {
            $entry = $this->postJournal($settlement);

            // تأكيد التسوية والاستحقاق (idempotent — أغلبه جرى عند الإغلاق 4.3).
            $reference = $settlement->number;
            foreach ($settlement->lines->pluck('order')->filter()->unique('id') as $order) {
                if ($order->settled_at === null) {
                    $order->update(['settled_at' => now()]);
                }
                $this->commissions->accrueForOrder($order);
                $this->commissions->markEligibleForOrder($order, $reference, $actor);
            }

            $settlement->update([
                'accounting_entry_id' => $entry->id,
                'posted_by' => $actor?->id ?? auth()->id(),
                'posted_at' => now(),
            ]);
        });

        return $settlement;
    }

    public function close(DeliverySettlement $settlement, ?User $actor = null): DeliverySettlement
    {
        $this->transition($settlement, 'closed');

        return $settlement;
    }

    public function cancel(DeliverySettlement $settlement, ?User $actor = null): DeliverySettlement
    {
        $this->transition($settlement, 'cancelled');

        return $settlement;
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    /** القيد المزدوج: Dr نقد(صافٍ) + Dr مصروف شحن(رسوم) + Dr خصومات = Cr ذمم شركات التوصيل(COD). */
    private function postJournal(DeliverySettlement $settlement): JournalEntry
    {
        $net = (float) $settlement->computed_net;
        $fees = (float) $settlement->computed_fees_total;
        $deductions = (float) $settlement->computed_deductions_total;
        $cod = (float) $settlement->computed_cod_total;

        $cashCode = config('accounting.treasury.cash_posting', '1011-0001');

        $lines = [];
        if ($net > 0) {
            $lines[] = ['account_code' => $cashCode, 'debit' => $net, 'credit' => 0, 'description' => __('صافي محصّل من المزوّد')];
        }
        if ($fees > 0) {
            $lines[] = ['account_code' => self::ACC_DELIVERY_EXPENSE, 'debit' => $fees, 'credit' => 0, 'description' => __('رسوم التوصيل')];
        }
        if ($deductions > 0) {
            $lines[] = ['account_code' => self::ACC_DISCOUNTS, 'debit' => $deductions, 'credit' => 0, 'description' => __('خصومات التسوية')];
        }
        // الطرف الدائن: «صندوق الأونلاين» إن كان مُهيّأً — فمنذ ADR-012g يُنقل تحصيل كل طلب
        // من «ذمم شركة التوصيل 1050» إلى الصندوق لحظة in_accounting، فتسوية المزوّد تُفرغ
        // الصندوق (تحويل للنقد الرئيسي + أجور الشركة). fallback: 1050 (بلا صندوق مُهيّأ).
        $codCreditCode = OrderPaymentService::codTreasury()?->glAccount?->code
            ?? self::ACC_COD_CLEARING;
        $lines[] = ['account_code' => $codCreditCode, 'debit' => 0, 'credit' => $cod, 'description' => __('تحصيل COD عبر المزوّد')];

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('تسوية توصيل :n', ['n' => $settlement->number]),
            'source' => 'system',
            'reference_type' => DeliverySettlement::class,
            'reference_id' => $settlement->id,
        ], $lines);
    }

    private function pushLine(DeliverySettlement $settlement, ?int $orderId, ?int $shipmentId, float $cod, float $fee, float $deduction, ?float $reportedCod = null, ?string $note = null): SettlementLine
    {
        return $settlement->lines()->create([
            'order_id' => $orderId,
            'shipment_id' => $shipmentId,
            'cod_amount' => round($cod, 2),
            'fee_amount' => round($fee, 2),
            'deduction_amount' => round($deduction, 2),
            'net_amount' => round($cod - $fee - $deduction, 2),
            'reported_cod' => $reportedCod,
            'note' => $note,
        ]);
    }

    private function recomputeComputed(DeliverySettlement $settlement): void
    {
        $lines = $settlement->lines()->get();
        $cod = round((float) $lines->sum('cod_amount'), 2);
        $fees = round((float) $lines->sum('fee_amount'), 2);
        $deductions = round((float) $lines->sum('deduction_amount'), 2);

        $settlement->update([
            'computed_cod_total' => $cod,
            'computed_fees_total' => $fees,
            'computed_deductions_total' => $deductions,
            'computed_net' => round($cod - $fees - $deductions, 2),
        ]);
    }

    private function transition(DeliverySettlement $settlement, string $to, ?callable $effect = null): void
    {
        $from = $settlement->status;
        if (! DeliverySettlement::canTransition($from, $to)) {
            throw ValidationException::withMessages(['status' => __('انتقال غير مسموح: :from → :to.', ['from' => $from, 'to' => $to])]);
        }

        DB::transaction(function () use ($settlement, $to, $effect) {
            if ($effect) {
                $effect();
            }
            $settlement->update(['status' => $to]);
        });
    }

    private function assertDraft(DeliverySettlement $settlement): void
    {
        if ($settlement->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('لا يمكن تعديل السطور إلا في المسودّة.')]);
        }
    }
}
