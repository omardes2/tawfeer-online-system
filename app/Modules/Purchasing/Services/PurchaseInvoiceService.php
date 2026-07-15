<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Support\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly SupplierService $suppliers,
        private readonly ProductService $products,
    ) {}

    /**
     * حساب ذمم المورد المستخدَم في الترحيل: الحساب الفرعي للمورد إن وُجد،
     * وإلا الحساب العام «ذمم الموردين» (توافق رجعي). ينشئ الفرعي كسولًا إن غاب.
     */
    private function payableAccountCode(PurchaseInvoice $invoice): string
    {
        $supplier = $invoice->supplier;
        if ($supplier) {
            $account = $supplier->glAccount()->first() ?: $this->suppliers->ensureLedgerAccount($supplier);
            if ($account) {
                return $account->code;
            }
        }

        return config('accounting.purchasing.payable_account');
    }

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
            [$prepared, $subtotal, $tax] = $this->prepareItems($items);

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
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'currency' => $data['currency'] ?? config('app.currency', 'ILS'),
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->createMany($prepared);

            return $invoice->load('items');
        });
    }

    /**
     * تعديل فاتورة غير مُرحّلة (مسودّة/معتمدة): يعيد حساب الإجماليات ويستبدل البنود.
     * لا يُسمح بتعديل فاتورة مُرحّلة (تُصحَّح بالعكس) أو ملغاة.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function update(PurchaseInvoice $invoice, array $data, array $items): PurchaseInvoice
    {
        if (! in_array($invoice->status, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => __('لا يمكن تعديل فاتورة مُرحّلة أو ملغاة.')]);
        }

        return DB::transaction(function () use ($invoice, $data, $items) {
            [$prepared, $subtotal, $tax] = $this->prepareItems($items);
            $date = Carbon::parse($data['invoice_date'] ?? $invoice->invoice_date);

            $invoice->update([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->delete();
            $invoice->items()->createMany($prepared);

            return $invoice->load('items');
        });
    }

    /**
     * تجهيز بنود الفاتورة وحساب الإجماليات (الإجمالي الفرعي والضريبة).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float}
     */
    private function prepareItems(array $items): array
    {
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
                // الصنف الجديد: يُحفظ اسمه/سعره ويُنشأ المنتج عند الترحيل فقط (لا في المسودّة).
                'new_product_name' => $it['new_product_name'] ?? null,
                'new_product_sell_price' => isset($it['new_product_sell_price']) ? (float) $it['new_product_sell_price'] : null,
                'qty' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'line_total' => $line,
            ];
        }

        return [$prepared, round($subtotal, 2), round($tax, 2)];
    }

    /** ينشئ منتجًا/متغيّرًا من بند «صنف جديد» — يُستدعى عند الترحيل فقط. */
    private function createProductVariant(PurchaseInvoiceItem $item): ProductVariant
    {
        $sell = $item->new_product_sell_price !== null ? (float) $item->new_product_sell_price : (float) $item->unit_cost;

        $product = $this->products->create([
            'name' => $item->new_product_name,
            'sku' => $this->uniqueSku(),
            'category_id' => $this->defaultCategoryId(),
            'unit_id' => $this->defaultUnitId(),
            'status' => 'active',
            'retail_price' => $sell,
            'cost_price' => (float) $item->unit_cost,
        ]);

        $variant = $product->defaultVariant()->firstOrFail();
        $variant->update(['retail_price' => $sell, 'cost_price' => (float) $item->unit_cost]);

        return $variant;
    }

    private function uniqueSku(): string
    {
        do {
            $sku = 'P-'.Str::upper(Str::random(8));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    /** فئة افتراضية للأصناف المُنشأة من الفاتورة (تُنشأ إن لم توجد أي فئة). */
    private function defaultCategoryId(): int
    {
        return Category::orderBy('id')->value('id')
            ?? Category::firstOrCreate(['slug' => 'uncategorized'], ['name' => __('غير مصنّف'), 'is_active' => true])->id;
    }

    /** وحدة قياس افتراضية (تُنشأ إن لم توجد أي وحدة). */
    private function defaultUnitId(): int
    {
        return Unit::orderBy('id')->value('id')
            ?? Unit::firstOrCreate(['code' => 'PCS'], ['name' => __('قطعة'), 'is_active' => true])->id;
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
            $lines[] = ['account_code' => $this->payableAccountCode($invoice), 'debit' => 0, 'credit' => (float) $invoice->total];

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

            // تعريف الأصناف الجديدة عند الترحيل فقط: تُنشأ المنتجات/المتغيّرات الآن (لا في المسودّة).
            foreach ($invoice->items as $item) {
                if (! $item->variant_id && $item->new_product_name) {
                    $item->update(['variant_id' => $this->createProductVariant($item)->id]);
                }
            }
            $invoice->load('items');

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

        // نُخصم من حساب المورد الفرعي نفسه المستخدَم في الترحيل (يبقى رصيده صحيحًا).
        $payable = Account::where('code', $this->payableAccountCode($invoice))->firstOrFail();

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
