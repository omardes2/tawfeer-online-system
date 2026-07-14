<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Support\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * فواتير الموردين/الشراء (REQUIREMENTS §2.5) — مبنيّة على محرّك القيد المزدوج.
 * الترحيل: مدين المخزون [+ ضريبة] / دائن ذمم الموردين. الدفع يعيد استخدام سند الصرف
 * (Phase 7.1): مدين ذمم الموردين / دائن الخزنة. عكس لا حذف. كل العمليات داخل معاملة.
 */
class PurchaseInvoiceService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly VoucherService $vouchers,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * إنشاء فاتورة (مسودّة) ببنودها. يحسب الإجماليات من البنود.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function create(array $data, array $items): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $items) {
            $date = Carbon::parse($data['invoice_date'] ?? now()->toDateString());

            $subtotal = 0.0;
            $tax = 0.0;
            $prepared = [];
            foreach ($items as $it) {
                $qty = (float) ($it['qty'] ?? 1);
                $cost = (float) ($it['unit_cost'] ?? 0);
                $rate = (float) ($it['tax_rate'] ?? 0);
                $line = round($qty * $cost, 2);
                $lineTax = round($line * $rate / 100, 2);
                $subtotal += $line;
                $tax += $lineTax;
                $prepared[] = [
                    'variant_id' => $it['variant_id'] ?? null,
                    'description' => $it['description'] ?? null,
                    'qty' => $qty,
                    'unit_cost' => $cost,
                    'tax_rate' => $rate,
                    'tax_amount' => $lineTax,
                    'line_total' => $line,
                ];
            }

            $invoice = PurchaseInvoice::create([
                'number' => NumberGenerator::next('purchase_invoices', 'number', 'PINV', (int) $date->year),
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($tax, 2),
                'total' => round($subtotal + $tax, 2),
                'currency' => $data['currency'] ?? config('app.currency', 'ILS'),
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->createMany($prepared);

            return $invoice->load('items');
        });
    }

    public function approve(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->assertTransition($invoice, 'approved');
        $invoice->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $invoice;
    }

    public function cancel(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->assertTransition($invoice, 'cancelled');
        $invoice->update(['status' => 'cancelled']);

        return $invoice;
    }

    /** ترحيل: مدين المخزون [+ ضريبة] / دائن ذمم الموردين — idempotent. */
    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->journal_entry_id !== null) {
            return $invoice; // مُرحّلة سابقًا (حارس idempotency).
        }
        if ($invoice->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('تُرحَّل الفاتورة المعتمدة فقط.')]);
        }

        return DB::transaction(function () use ($invoice) {
            $cfg = config('accounting.purchasing');
            $lines = [
                ['account_code' => $cfg['inventory_account'], 'debit' => (float) $invoice->subtotal, 'credit' => 0],
            ];
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = ['account_code' => $cfg['tax_account'], 'debit' => (float) $invoice->tax_amount, 'credit' => 0];
            }
            $lines[] = ['account_code' => $cfg['payable_account'], 'debit' => 0, 'credit' => (float) $invoice->total];

            $entry = $this->accounting->postEntry([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'description' => __('فاتورة شراء :n', ['n' => $invoice->number]),
                'source' => 'purchase_invoice',
                'reference_type' => 'purchase_invoice',
                'reference_id' => $invoice->id,
                'idempotency_key' => 'purchase_invoice:'.$invoice->id,
            ], $lines);

            $invoice->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            // إدخال البضاعة للمخزون: كل بند بمتغيّر يزيد الكمية بالتكلفة (WAC) في المستودع الافتراضي.
            if ($invoice->goods_receipt_id === null) {
                $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
                if ($warehouse) {
                    foreach ($invoice->items as $item) {
                        if (! $item->variant_id) {
                            continue;
                        }
                        $variant = ProductVariant::find($item->variant_id);
                        if (! $variant) {
                            continue;
                        }
                        $this->inventory->receive($variant, $warehouse, (float) $item->qty, (float) $item->unit_cost, [
                            'reference_type' => PurchaseInvoice::class,
                            'reference_id' => $invoice->id,
                            'reason' => 'purchase_invoice:'.$invoice->number,
                        ]);
                    }
                }
            }

            return $invoice;
        });
    }

    /**
     * دفع (جزئي/كامل) لفاتورة مُرحّلة عبر سند صرف: مدين ذمم الموردين / دائن الخزنة.
     * يعيد استخدام VoucherService (Phase 7.1) فتبقى أرصدة الخزائن مشتقّة وصحيحة.
     */
    public function pay(PurchaseInvoice $invoice, int $treasuryId, float $amount, ?string $date = null): PurchaseInvoice
    {
        if ($invoice->status !== 'posted') {
            throw ValidationException::withMessages(['status' => __('لا يمكن دفع فاتورة غير مُرحّلة.')]);
        }
        $due = $invoice->balanceDue();
        if ($amount <= 0 || $amount > $due + 0.001) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون بين 0 والمتبقّي (:due).', ['due' => number_format($due, 2)])]);
        }

        $payable = Account::where('code', config('accounting.purchasing.payable_account'))->firstOrFail();

        return DB::transaction(function () use ($invoice, $treasuryId, $amount, $date, $payable) {
            $voucher = $this->vouchers->create('payment', [
                'treasury_id' => $treasuryId,
                'amount' => $amount,
                'counter_account_id' => $payable->id,
                'supplier_id' => $invoice->supplier_id,
                'reference' => $invoice->number,
                'description' => __('دفعة فاتورة شراء :n', ['n' => $invoice->number]),
                'voucher_date' => $date ?? now()->toDateString(),
            ]);
            $this->vouchers->approve($voucher);
            $this->vouchers->post($voucher);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'payment_status' => $paid + 0.001 >= (float) $invoice->total ? 'paid' : 'partial',
            ]);

            return $invoice;
        });
    }

    /** عكس ترحيل الفاتورة (لا حذف). يُمنع إن سُدّد منها شيء. */
    public function reverse(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status !== 'posted' || ! $invoice->journalEntry) {
            throw ValidationException::withMessages(['status' => __('لا يمكن عكس فاتورة غير مُرحّلة.')]);
        }
        if ((float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['status' => __('لا يمكن عكس فاتورة سُدّد منها دفعات — اعكس الدفعات أولًا.')]);
        }

        return DB::transaction(function () use ($invoice) {
            $reversal = $this->accounting->reverse($invoice->journalEntry);
            $invoice->update(['status' => 'reversed', 'reversal_entry_id' => $reversal->id]);

            return $invoice;
        });
    }

    private function assertTransition(PurchaseInvoice $invoice, string $to): void
    {
        if (! $invoice->canTransition($to)) {
            throw ValidationException::withMessages(['status' => __('انتقال غير مسموح من :from إلى :to.', ['from' => $invoice->status, 'to' => $to])]);
        }
    }
}
