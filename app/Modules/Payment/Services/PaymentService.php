<?php

namespace App\Modules\Payment\Services;

use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Sales\Models\Order;
use App\Support\Contracts\Payment\PaymentChargeRequest;
use App\Support\Contracts\Payment\PaymentResult;
use App\Support\Integrations\Payment\PaymentProviderManager;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق المدفوعات (ADR-028). كل تفاعل مزوّد يمرّ حصريًا عبر PaymentProviderManager (طبقة التكامل).
 * حالة الطلب المالية (payment_status/amount_paid) تُشتقّ من المدفوعات الناجحة (مفردات payment_statuses).
 */
class PaymentService
{
    public function __construct(private readonly PaymentProviderManager $providers) {}

    /**
     * بدء دفعة لطلب عبر طريقة دفع (يمرّ عبر مزوّد الطريقة).
     */
    public function initiate(Order $order, PaymentMethod $method, float $amount, int $year, array $opts = []): Payment
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون أكبر من صفر.')]);
        }
        if (! $method->is_active) {
            throw ValidationException::withMessages(['method' => __('طريقة الدفع غير مُفعّلة.')]);
        }
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => __('لا يمكن تسجيل دفعة على طلب ملغى.')]);
        }

        return DB::transaction(function () use ($order, $method, $amount, $year, $opts) {
            $payment = Payment::create([
                'number' => NumberGenerator::next('payments', 'number', 'PAY', $year),
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'currency' => $opts['currency'] ?? null,
                'status' => 'pending',
                'notes' => $opts['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $provider = $this->providers->for($method);
            $result = $provider->charge(new PaymentChargeRequest(
                amountMinor: (int) round($amount * 100),
                currency: $opts['currency'] ?? 'SAR',
                orderNumber: $order->number,
                methodCode: $method->code,
            ));

            $payment->update([
                'status' => $this->mapStatus($result->status),
                'provider_reference' => $result->reference,
                'provider_metadata' => $result->raw ?: null,
            ]);
            $this->recordTransaction($payment, 'charge', $result, $amount);

            if ($result->isPaid()) {
                $this->settlePaid($payment);
            }

            return $payment;
        });
    }

    /**
     * تأكيد/تحصيل دفعة (تحصيل COD عند التسليم، أو capture لبوابة).
     */
    public function capture(Payment $payment): Payment
    {
        $this->assertStatus($payment, ['pending', 'processing']);

        return DB::transaction(function () use ($payment) {
            $provider = $this->providers->for($payment->method);
            $result = $provider->capture((string) $payment->provider_reference, (int) round((float) $payment->amount * 100));

            $this->recordTransaction($payment, 'capture', $result, (float) $payment->amount);

            if ($result->isPaid()) {
                $this->settlePaid($payment, $result->reference);
            } else {
                $payment->update(['status' => $this->mapStatus($result->status)]);
            }

            return $payment;
        });
    }

    /**
     * معالجة callback/webhook من مزوّد (يتحقّق عبر طبقة التكامل ويحدّث الحالة).
     */
    public function handleCallback(Payment $payment, array $payload): Payment
    {
        return DB::transaction(function () use ($payment, $payload) {
            $provider = $this->providers->for($payment->method);
            $result = $provider->verify((string) $payment->provider_reference);

            $this->recordTransaction($payment, 'callback', $result, null, $payload);

            if ($result->isPaid() && $payment->status !== 'paid') {
                $this->settlePaid($payment, $result->reference);
            } elseif (in_array($result->status, ['failed', 'cancelled'], true)) {
                $payment->update(['status' => $result->status, 'failed_at' => now()]);
            }

            return $payment;
        });
    }

    /**
     * استرداد كامل أو جزئي لدفعة مسدَّدة.
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        $this->assertStatus($payment, ['paid', 'partially_refunded']);

        $remaining = (float) $payment->amount - (float) $payment->refunded_amount;
        $refundAmount = $amount ?? $remaining;
        if ($refundAmount <= 0 || $refundAmount > $remaining + 1e-9) {
            throw ValidationException::withMessages(['amount' => __('مبلغ الاسترداد غير صالح.')]);
        }

        return DB::transaction(function () use ($payment, $refundAmount) {
            $provider = $this->providers->for($payment->method);
            $result = $provider->refund((string) $payment->provider_reference, (int) round($refundAmount * 100));

            $this->recordTransaction($payment, 'refund', $result, $refundAmount);

            $newRefunded = (float) $payment->refunded_amount + $refundAmount;
            $fully = $newRefunded + 1e-9 >= (float) $payment->amount;
            $payment->update([
                'refunded_amount' => $newRefunded,
                'status' => $fully ? 'refunded' : 'partially_refunded',
                'refunded_at' => now(),
            ]);

            $this->recomputeOrderPaymentStatus($payment->order);

            return $payment;
        });
    }

    /**
     * إعادة اشتقاق حالة دفع الطلب من المدفوعات (BR — مفردات payment_statuses).
     */
    public function recomputeOrderPaymentStatus(Order $order): void
    {
        // إعادة تحميل نسخة حديثة: تفادي حالة قديمة على نسخة مخبّأة (dirty-check يتخطّى قيمة مطابقة).
        $order = $order->fresh() ?? $order;

        // كل دفعة وصلت "paid" تُحتسب مقبوضة (حتى لو رُدّ جزء منها لاحقًا).
        $collected = (float) $order->payments()
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->sum('amount');
        $refunded = (float) $order->payments()->sum('refunded_amount');
        $net = $collected - $refunded;
        $total = (float) $order->total;

        if ($net + 1e-9 >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($net > 1e-9) {
            $status = 'partially_paid';
        } elseif ($refunded > 1e-9) {
            $status = 'refunded'; // قُبض ثم رُدّ بالكامل
        } else {
            $status = 'unpaid';
        }

        $order->update(['amount_paid' => max($net, 0), 'payment_status' => $status]);
    }

    private function settlePaid(Payment $payment, ?string $reference = null): void
    {
        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'provider_reference' => $reference ?: $payment->provider_reference,
        ]);

        $this->recomputeOrderPaymentStatus($payment->order);
    }

    private function recordTransaction(Payment $payment, string $kind, PaymentResult $result, ?float $amount, array $requestPayload = []): void
    {
        $payment->transactions()->create([
            'kind' => $kind,
            'status' => $result->status,
            'amount' => $amount,
            'provider_reference' => $result->reference,
            'request_payload' => $requestPayload ?: null,
            'response_payload' => $result->raw ?: null,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    private function mapStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'paid' => 'paid',
            'processing' => 'processing',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    private function assertStatus(Payment $payment, array $allowed): void
    {
        if (! in_array($payment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('العملية غير مسموحة على دفعة بحالة :status.', ['status' => $payment->status]),
            ]);
        }
    }
}
