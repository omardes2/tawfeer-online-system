<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Sales\Events\AssistedOrderCreated;
use App\Modules\Sales\Events\OrderPriceChangeApproved;
use App\Modules\Sales\Events\OrderPriceChangeRejected;
use App\Modules\Sales\Events\OrderPriceChangeRequested;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderPriceChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * البيع المُساعد (Phase 4.1 / ADR-037): إنشاء طلب من قناة مع بحث/إنشاء عميل بالهاتف،
 * تعديل سعر يدوي مضبوط بالموافقة، ولقطات كاملة. **يُعيد استخدام** OrderService (الحجز/
 * الحالات/اللقطة) وCustomerService (التطبيع/الحظر) — لا تكرار منطق. سجلّ تغييرات السعر
 * غير قابل للتعديل. الموافقة مطلوبة تحت min_price أو التكلفة ما لم يملك المنفّذ التجاوز.
 */
class AssistedOrderService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CustomerService $customers,
    ) {}

    /** بحث عن عميل بالهاتف المطبّع أو إنشاؤه (BR-CUST-03/05). */
    public function resolveCustomer(string $phone, array $data): Customer
    {
        $existing = $this->customers->findDuplicatesByPhone($phone)->first();
        if ($existing) {
            return $existing;
        }

        return $this->customers->create([
            'name' => $data['customer_name'],
            'primary_phone' => $phone,
            'email' => $data['customer_email'] ?? null,
            'branch_id' => $data['branch_id'],
            'source' => 'assisted:'.($data['channel'] ?? 'manual'),
        ]);
    }

    /**
     * إنشاء طلب مُساعد. القناة والإسناد ولقطات السعر. المنفّذ يحدّد الحاجة للاعتماد.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items  variant_id/qty/unit_price/discount?/reason?
     */
    public function create(User $actor, array $data, array $items): Order
    {
        $customer = $this->resolveCustomer($data['customer_phone'], $data);
        $canOverride = $actor->can('sales.orders.override_price');

        // تجهيز البنود مع اللقطات وتحديد ما يتطلّب اعتمادًا.
        $prepared = array_map(function (array $line) use ($canOverride) {
            $variant = ProductVariant::findOrFail($line['variant_id']);
            $retail = (float) $variant->retail_price;
            $wholesale = (float) $variant->average_cost;
            $min = (float) $variant->min_price;
            $price = (float) $line['unit_price'];
            $belowLimit = $price < $min - 1e-9 || ($wholesale > 0 && $price < $wholesale - 1e-9);

            return [
                'variant_id' => $variant->id,
                'qty' => (float) $line['qty'],
                'unit_price' => $price,
                'discount' => (float) ($line['discount'] ?? 0),
                'retail' => $retail,
                'wholesale' => $wholesale,
                'min' => $min,
                'reason' => $line['reason'] ?? null,
                'edited' => abs($price - $retail) > 1e-9,
                'requires_approval' => $belowLimit && ! $canOverride,
            ];
        }, $items);

        return DB::transaction(function () use ($actor, $data, $customer, $prepared) {
            $order = $this->orders->create(
                [
                    'branch_id' => $data['branch_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $data['customer_phone'],
                    'customer_email' => $data['customer_email'] ?? $customer->email,
                    'shipping_address' => $data['shipping_address'] ?? null,
                    'channel' => $data['channel'],
                    'assigned_to' => $data['assigned_to'] ?? $actor->id,
                    'notes' => $data['notes'] ?? null,
                ],
                array_map(fn ($p) => [
                    'variant_id' => $p['variant_id'],
                    'qty' => $p['qty'],
                    'unit_price' => $p['unit_price'],
                    'discount' => $p['discount'],
                ], $prepared),
                (int) now()->year,
            );

            $order->update(['affiliate_id' => $data['affiliate_id'] ?? null]);

            foreach ($prepared as $p) {
                $item = $order->items()->where('variant_id', $p['variant_id'])->first();
                $autoApproved = $p['edited'] && ! $p['requires_approval'];

                $item->update([
                    'retail_price_snapshot' => $p['retail'],
                    'wholesale_cost_snapshot' => $p['wholesale'],
                    'price_change_reason' => $p['edited'] ? $p['reason'] : null,
                    'price_approved_by' => $autoApproved ? $actor->id : null,
                ]);

                if ($p['edited']) {
                    $change = OrderPriceChange::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'variant_id' => $p['variant_id'],
                        'original_retail' => $p['retail'],
                        'wholesale_cost' => $p['wholesale'],
                        'min_price' => $p['min'],
                        'new_price' => $p['unit_price'],
                        'discount' => $p['discount'],
                        'reason' => $p['reason'],
                        'requires_approval' => $p['requires_approval'],
                        'status' => $p['requires_approval'] ? 'pending' : 'auto_approved',
                        'requested_by' => $actor->id,
                        'approved_by' => $p['requires_approval'] ? null : $actor->id,
                        'decided_at' => $p['requires_approval'] ? null : now(),
                    ]);

                    if ($p['requires_approval']) {
                        OrderPriceChangeRequested::dispatch($change);
                    }
                }
            }

            AssistedOrderCreated::dispatch($order);

            return $order->fresh(['items', 'priceChanges']);
        });
    }

    /** اعتماد تعديل سعر معلّق (مشرف). */
    public function approvePriceChange(User $actor, OrderPriceChange $change): OrderPriceChange
    {
        $this->assertPending($change);

        return DB::transaction(function () use ($actor, $change) {
            $change->update(['status' => 'approved', 'approved_by' => $actor->id, 'decided_at' => now()]);
            $change->orderItem?->update(['price_approved_by' => $actor->id]);
            OrderPriceChangeApproved::dispatch($change);

            return $change->refresh();
        });
    }

    /** رفض تعديل سعر معلّق (مشرف) بسبب موثّق. */
    public function rejectPriceChange(User $actor, OrderPriceChange $change, ?string $reason = null): OrderPriceChange
    {
        $this->assertPending($change);

        return DB::transaction(function () use ($actor, $change, $reason) {
            $change->update([
                'status' => 'rejected',
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'reason' => $reason ?: $change->reason,
            ]);
            OrderPriceChangeRejected::dispatch($change);

            return $change->refresh();
        });
    }

    private function assertPending(OrderPriceChange $change): void
    {
        if ($change->status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('طلب التعديل لم يعد معلّقًا.')]);
        }
    }
}
