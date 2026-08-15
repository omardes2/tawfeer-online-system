<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalEntry;
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
    /**
     * منازل الحجم — ستٌّ لا أربع: قطعةٌ حجمها 0.00531 م³ تُقرَّب بأربعٍ إلى
     * 0.0053، فينحرف نصيبُها من الشحن البحري ويتراكم الخطأ على آلاف القطع.
     * ستّ منازل = دقّة السنتيمتر المكعّب الواحد.
     */
    public const CBM_SCALE = 6;

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
            $kind = $this->resolveKind($data);
            $totals = $this->prepareItems($items, $this->calculatorFor($data, $kind), $kind);
            $subtotal = $totals['subtotal'];
            $tax = $totals['tax'];

            $invoice = PurchaseInvoice::create([
                'number' => NumberGenerator::next('purchase_invoices', 'number', 'PINV', (int) $date->year),
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'import_shipment_id' => $data['import_shipment_id'] ?? null,
                'kind' => $kind,
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
                ...$this->importAttributes($data, $totals, $kind),
            ]);

            $invoice->items()->createMany($totals['items']);

            return $invoice->load('items');
        });
    }

    /**
     * إنشاء فاتورة وترحيلها محاسبيًا فورًا في خطوة واحدة (قرار إداري: لا مسودّة ولا اعتماد
     * منفصل). كل شيء داخل معاملة واحدة: إن فشل الترحيل لا تبقى فاتورة معلّقة.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createAndPost(array $data, array $items): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $items) {
            $invoice = $this->create($data, $items);
            $invoice->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

            return $this->post($invoice->fresh('items'));
        });
    }

    /**
     * تعديل فاتورة **مُرحّلة**: يعكس أثر المخزون القديم، يستبدل البنود، يُدخل الجديد،
     * ويُحدّث القيد المحاسبي **في مكانه** (نفس رقم القيد) بدل إنشاء قيد جديد — مطابقًا
     * لسياسة فواتير المبيعات. كل ذلك داخل معاملة واحدة.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updatePosted(PurchaseInvoice $invoice, array $data, array $items): PurchaseInvoice
    {
        if ($invoice->status !== 'posted') {
            return $this->update($invoice, $data, $items); // غير مُرحّلة ⇒ المسار العادي.
        }
        if ((float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages([
                'status' => __('لا يمكن تعديل فاتورة سُدّد جزء منها — اعكس الدفعات أولًا.'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $items) {
            $this->reverseStock($invoice);            // 1) سحب البضاعة المُدخَلة سابقًا.

            $kind = $this->resolveKind($data, $invoice);
            $totals = $this->prepareItems($items, $this->calculatorFor($data, $kind), $kind);
            $subtotal = $totals['subtotal'];
            $tax = $totals['tax'];
            $date = Carbon::parse($data['invoice_date'] ?? $invoice->invoice_date);

            $invoice->update([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'import_shipment_id' => $data['import_shipment_id'] ?? null,
                'kind' => $kind,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'notes' => $data['notes'] ?? null,
                ...$this->importAttributes($data, $totals, $kind),
            ]);
            $invoice->items()->delete();
            $invoice->items()->createMany($totals['items']);
            $invoice->load('items');

            // 2) إنشاء متغيّرات الأصناف الجديدة ثم إدخال البضاعة بالكميات/التكاليف الجديدة.
            foreach ($invoice->items as $item) {
                if (! $item->variant_id && $item->new_product_name) {
                    $item->update(['variant_id' => $this->createProductVariant($item)->id]);
                }
            }
            $this->applyStock($invoice->load('items'));

            // 3) تحديث القيد المحاسبي في مكانه بالمبالغ الجديدة.
            $entry = $invoice->journal_entry_id ? JournalEntry::find($invoice->journal_entry_id) : null;
            if ($entry) {
                $this->accounting->replaceLines($entry, $this->postingLines($invoice));
            }

            return $invoice->load('items');
        });
    }

    /**
     * حذف فاتورة **مُرحّلة** نهائيًا: يسحب البضاعة المُدخَلة ويحذف قيدها المحاسبي
     * (لا عكس) — مطابقًا لسياسة حذف فاتورة المبيعات. المسدَّدة تُمنع.
     */
    /**
     * حذف فاتورة مشتريات مع **عكس كامل لأثرها**: الدفعات المُرحّلة تُعكس (يعود النقد
     * للخزينة)، ثم يُسحب المخزون، ثم يُعكس قيد الشراء (فتُصفَّر ذمّة المورد). المستند
     * يُحذف حذفًا ناعمًا. كل ذلك داخل معاملة واحدة، وكل خطوة idempotent.
     */
    public function deletePosted(PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            // عكس دفعات الفاتورة المُرحّلة (مرجعها رقم الفاتورة) — يعيد المال للخزينة
            // ويُلغي أثرها على حساب المورد قبل عكس قيد الشراء نفسه.
            FinancialVoucher::where('kind', 'payment')
                ->where('reference', $invoice->number)
                ->where('status', 'posted')
                ->get()
                ->each(fn (FinancialVoucher $v) => $this->vouchers->reverse(
                    $v, __('حذف فاتورة مشتريات :n', ['n' => $invoice->number]),
                ));

            // قيود فروق الصرف ليست سندات، فلا تلتقطها الحلقة أعلاه — ولو تُركت
            // بقيت على المورد ذمّةٌ وهمية بمقدار الفرق.
            JournalEntry::where('source', 'purchase_invoice_fx')
                ->where('reference_id', $invoice->id)
                ->get()
                ->each(function (JournalEntry $entry) use ($invoice) {
                    if ($entry->isPosted() && ! $entry->isReversed()) {
                        $this->accounting->reverse($entry, [
                            'description' => __('عكس فرق صرف فاتورة :n (حذف)', ['n' => $invoice->number]),
                        ]);
                    }
                });

            $invoice->update(['amount_paid' => 0, 'payment_status' => 'unpaid']);

            if ($invoice->status === 'posted') {
                $this->reverseStock($invoice);

                // **عكس** قيد الشراء لا حذفه (ADR-016): الأثر المالي صفر كما في الحذف،
                // ويبقى القيد وعكسه في الدفتر بلا فجوة في الترقيم. المسودّة تُحذف (بلا أثر).
                $entry = $invoice->journal_entry_id ? JournalEntry::find($invoice->journal_entry_id) : null;
                if ($entry) {
                    if (! $entry->isPosted()) {
                        $this->accounting->deleteEntry($entry);
                    } elseif (! $entry->isReversed()) {
                        $this->accounting->reverse($entry, [
                            'description' => __('عكس فاتورة مشتريات :n (حذف)', ['n' => $invoice->number]),
                        ]);
                    }
                }
                $invoice->update(['journal_entry_id' => null]);
            }

            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    /**
     * سطور قيد الشراء: مدين المخزون [+ ضريبة] / دائن ذمم المورد.
     *
     * في فاتورة الاستيراد يُدان المخزون بالتكلفة **الشاملة** لا بسعر المورد —
     * فتدخل البضاعة بقيمتها الحقيقية من أول يوم بلا انتظارِ فاتورة الشحن —
     * بينما تبقى ذمّة المورد بسعرها الحقيقي. الفرق يُقيَّد في «مصاريف استيراد
     * مستحقة»: التزامٌ حقيقي لشركة الشحن/المكتب لم تصل فاتورته بعد.
     *
     * وإن كتب المستخدم تكلفةً أقلّ من سعر المورد انقلب الفرق مدينًا — والاتجاه
     * يُشتقّ من الإشارة لا يُفترض.
     */
    private function postingLines(PurchaseInvoice $invoice): array
    {
        $cfg = config('accounting.purchasing');

        // فاتورة مصاريف الشحنة تُدين الحساب الوسيط لا المخزون: هي الفاتورة التي
        // **تُطفئ** ما حمّلته فاتورةُ البضاعة من تقدير، ولا تُدخل بضاعة جديدة.
        if ($invoice->isExpenseInvoice()) {
            $lines = [['account_code' => $cfg['import_accrual_account'], 'debit' => (float) $invoice->subtotal, 'credit' => 0]];
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = ['account_code' => $cfg['tax_account'], 'debit' => (float) $invoice->tax_amount, 'credit' => 0];
            }
            $lines[] = ['account_code' => $this->payableAccountCode($invoice), 'debit' => 0, 'credit' => (float) $invoice->total];

            return $lines;
        }

        $inventoryValue = $invoice->isImport() ? (float) $invoice->landed_subtotal : (float) $invoice->subtotal;

        $lines = [['account_code' => $cfg['inventory_account'], 'debit' => $inventoryValue, 'credit' => 0]];
        if ((float) $invoice->tax_amount > 0) {
            $lines[] = ['account_code' => $cfg['tax_account'], 'debit' => (float) $invoice->tax_amount, 'credit' => 0];
        }
        $lines[] = ['account_code' => $this->payableAccountCode($invoice), 'debit' => 0, 'credit' => (float) $invoice->total];

        // السطر لا يُضاف إلا بفرق فعلي: القيد يرفض سطرًا صفريًا، والتكلفةُ
        // المطابقة لسعر المورد لا تُنشئ التزامًا.
        $difference = $invoice->isImport() ? $invoice->importDifference() : 0.0;
        if (abs($difference) >= 0.01) {
            $lines[] = [
                'account_code' => $cfg['import_accrual_account'],
                'debit' => $difference < 0 ? abs($difference) : 0,
                'credit' => $difference > 0 ? $difference : 0,
                'description' => __('مصاريف محمّلة على بضاعة فاتورة :n', ['n' => $invoice->number]),
            ];
        }

        return $lines;
    }

    /** إدخال بضاعة الفاتورة للمخزون (المستودع الافتراضي) — يُستخدم عند الترحيل والتعديل. */
    private function applyStock(PurchaseInvoice $invoice): void
    {
        if ($invoice->isExpenseInvoice()) {
            return; // مصاريف لا بضاعة.
        }
        if ($invoice->goods_receipt_id !== null) {
            return; // دخلت عبر إذن استلام مستقل.
        }
        $warehouse = $this->defaultWarehouse();
        if (! $warehouse) {
            return;
        }

        foreach ($invoice->items as $item) {
            $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;
            if (! $variant) {
                continue;
            }
            // البضاعة تدخل بتكلفتها **الشاملة** — وهي ما يُحتسب عليه متوسط التكلفة
            // وربحُ ما يُباع لاحقًا. (تساوي سعر المورد في الفاتورة المحلية.)
            $this->inventory->receive($variant, $warehouse, (float) $item->qty, $this->stockUnitCost($item), [
                'reference_type' => PurchaseInvoice::class,
                'reference_id' => $invoice->id,
                'reason' => 'purchase_invoice:'.$invoice->number,
            ]);
        }
    }

    /**
     * سحب البضاعة التي أدخلتها الفاتورة (عكس الاستلام) عند تعديلها أو حذفها.
     * يفشل بوضوح إن بيعت الكمية ولم تعُد متاحة — أفضل من إفساد رصيد المخزون.
     */
    private function reverseStock(PurchaseInvoice $invoice): void
    {
        if ($invoice->isExpenseInvoice() || $invoice->goods_receipt_id !== null) {
            return;
        }
        $warehouse = $this->defaultWarehouse();
        if (! $warehouse) {
            return;
        }

        foreach ($invoice->items as $item) {
            $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;
            if (! $variant || (float) $item->qty <= 0) {
                continue;
            }
            $this->inventory->purchaseReturn($variant, $warehouse, (float) $item->qty, [
                'reference_type' => PurchaseInvoice::class,
                'reference_id' => $invoice->id,
                'reason' => 'purchase_invoice_revert:'.$invoice->number,
            ]);
        }
    }

    /**
     * تكلفة الوحدة المعتمدة للمخزون وبطاقة الصنف: التكلفة الشاملة إن حُسبت، وإلا
     * سعر الفاتورة. الاحتياط يحمي فواتير سابقة رُحّلت قبل وجود عمود التكلفة.
     */
    private function stockUnitCost(PurchaseInvoiceItem $item): float
    {
        $landed = (float) $item->landed_unit_cost;

        return $landed > 0 ? $landed : (float) $item->unit_cost;
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
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
            $kind = $this->resolveKind($data, $invoice);
            $totals = $this->prepareItems($items, $this->calculatorFor($data, $kind), $kind);
            $subtotal = $totals['subtotal'];
            $tax = $totals['tax'];
            $date = Carbon::parse($data['invoice_date'] ?? $invoice->invoice_date);

            $invoice->update([
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'import_shipment_id' => $data['import_shipment_id'] ?? null,
                'kind' => $kind,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'invoice_date' => $date->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => round($subtotal + $tax, 2),
                'notes' => $data['notes'] ?? null,
                ...$this->importAttributes($data, $totals, $kind),
            ]);

            $invoice->items()->delete();
            $invoice->items()->createMany($totals['items']);

            return $invoice->load('items');
        });
    }

    /**
     * تجهيز بنود الفاتورة وحساب الإجماليات.
     *
     * في فاتورة الاستيراد يُدخِل المستخدم سعر الوحدة بعملة المورد، فتُشتقّ منه
     * الخلفيةُ قيمتين: **السعر الحقيقي** بالعملة الأساسية (ذمّة المورد) و**التكلفة
     * الشاملة** (السعر + العمولة + الشحن حسب الحجم). حسابُ الواجهة لا يُصدَّق —
     * يُعاد هنا — إلا عمود التكلفة إن علّمه المستخدم يدويًا فيُؤخذ كما كتبه.
     *
     * في الفاتورة المحلية (بلا أسعار صرف) تبقى المعادلة كما كانت: التكلفة كما تُكتب.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, tax: float, foreign_subtotal: float, landed_subtotal: float, total_cbm: float}
     */
    private function prepareItems(array $items, ImportCostCalculator $calc, string $kind = PurchaseInvoice::KIND_GOODS): array
    {
        $isExpense = $kind === PurchaseInvoice::KIND_EXPENSES;

        $subtotal = 0.0;
        $tax = 0.0;
        $foreignSubtotal = 0.0;
        $landedSubtotal = 0.0;
        $totalCbm = 0.0;
        $prepared = [];

        foreach ($items as $it) {
            $qty = (float) ($it['qty'] ?? 1);
            $rate = (float) ($it['tax_rate'] ?? 0);
            $manual = (bool) ($it['landed_is_manual'] ?? false);

            if ($calc->isActive()) {
                $foreign = (float) ($it['unit_price_foreign'] ?? 0);
                // بند المصاريف بلا حجم: هو التكلفة نفسها لا صنفٌ تُوزَّع عليه.
                $cbm = $isExpense ? 0.0 : $this->resolveCbm($it);
                $cost = $calc->unitCostBase($foreign);
                $landed = $manual
                    ? round((float) ($it['landed_unit_cost'] ?? 0), ImportCostCalculator::UNIT_SCALE)
                    : $calc->landedUnitCostBase($foreign, $cbm);
            } else {
                $foreign = 0.0;
                $cbm = 0.0;
                $manual = false;
                $cost = round((float) ($it['unit_cost'] ?? 0), ImportCostCalculator::UNIT_SCALE);
                $landed = $cost; // بلا مصاريف استيراد: التكلفة الشاملة هي السعر نفسه.
            }

            $line = round($qty * $cost, 2);
            $landedLine = round($qty * $landed, 2);
            $lineTax = round($line * $rate / 100, 2);

            $subtotal += $line;
            $tax += $lineTax;
            $landedSubtotal += $landedLine;
            $foreignSubtotal += round($qty * $foreign, 2);
            $totalCbm += $qty * $cbm;

            $prepared[] = [
                // فاتورة المصاريف لا تحمل أصنافًا ولا تُنشئ منتجات — بنودها وصفٌ ومبلغ.
                'variant_id' => $isExpense ? null : ($it['variant_id'] ?? null),
                'description' => $it['description'] ?? null,
                // الصنف الجديد: يُحفظ اسمه/سعره ويُنشأ المنتج عند الترحيل فقط (لا في المسودّة).
                'new_product_name' => $isExpense ? null : ($it['new_product_name'] ?? null),
                'new_product_sell_price' => ! $isExpense && isset($it['new_product_sell_price']) ? (float) $it['new_product_sell_price'] : null,
                'qty' => $qty,
                'unit_price_foreign' => $foreign,
                'cbm_per_unit' => $cbm,
                'unit_cost' => $cost,
                'landed_unit_cost' => $landed,
                'landed_line_total' => $landedLine,
                'landed_is_manual' => $manual,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'line_total' => $line,
            ];
        }

        return [
            'items' => $prepared,
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'foreign_subtotal' => round($foreignSubtotal, 2),
            'landed_subtotal' => round($landedSubtotal, 2),
            'total_cbm' => round($totalCbm, self::CBM_SCALE),
        ];
    }

    /**
     * نوع الفاتورة. فاتورة المصاريف لا تكون إلا على شحنة — بغيرها لا يُعرف أيّ
     * تقديرٍ تُطفئ، فتُعامَل فاتورةَ بضاعة عاديّة بدل تحميل الحساب الوسيط بلا مرجع.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveKind(array $data, ?PurchaseInvoice $current = null): string
    {
        $kind = $data['kind'] ?? $current?->kind ?? PurchaseInvoice::KIND_GOODS;
        if ($kind !== PurchaseInvoice::KIND_EXPENSES) {
            return PurchaseInvoice::KIND_GOODS;
        }

        return empty($data['import_shipment_id'])
            ? PurchaseInvoice::KIND_GOODS
            : PurchaseInvoice::KIND_EXPENSES;
    }

    /**
     * حاسبة الفاتورة. بنود المصاريف تُحوَّل بالصرف فقط: العمولة والشحن يُحمَّلان
     * على البضاعة لا على فاتورة الشحن نفسها — وإلا حُمّلا مرّتين.
     *
     * @param  array<string, mixed>  $data
     */
    private function calculatorFor(array $data, string $kind): ImportCostCalculator
    {
        if ($kind === PurchaseInvoice::KIND_EXPENSES) {
            $data['commission_rate'] = 0;
            $data['cbm_rate_usd'] = 0;
        }

        return ImportCostCalculator::fromArray($data);
    }

    /**
     * حجم الوحدة: ما كُتب في البند، وإلا حجم المتغيّر، وإلا حجم المنتج. فيكفي أن
     * يُسجَّل الحجم مرة واحدة في كرت الصنف ويبقى قابلًا للتخصيص لهذه الشحنة.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveCbm(array $item): float
    {
        if (isset($item['cbm_per_unit']) && $item['cbm_per_unit'] !== '' && (float) $item['cbm_per_unit'] > 0) {
            return round((float) $item['cbm_per_unit'], self::CBM_SCALE);
        }
        if (empty($item['variant_id'])) {
            return 0.0;
        }

        $variant = ProductVariant::with('product:id,cbm')->find($item['variant_id']);

        return round((float) ($variant?->cbm ?? $variant?->product?->cbm ?? 0), self::CBM_SCALE);
    }

    /**
     * رأس بيانات الاستيراد كما يُحفظ على الفاتورة. أسعار الصرف تُحفظ null لا صفرًا:
     * «فاتورة محلية» حالةٌ صريحة لا رقمٌ صفريّ يُقسَم عليه لاحقًا.
     *
     * @param  array<string, mixed>  $data
     * @param  array{foreign_subtotal: float, landed_subtotal: float, total_cbm: float}  $totals
     * @return array<string, mixed>
     */
    private function importAttributes(array $data, array $totals, string $kind = PurchaseInvoice::KIND_GOODS): array
    {
        $fx = (float) ($data['fx_rate_to_usd'] ?? 0);
        $usd = (float) ($data['usd_rate'] ?? 0);
        $isExpense = $kind === PurchaseInvoice::KIND_EXPENSES;

        return [
            'fx_rate_to_usd' => $fx > 0 ? $fx : null,
            'usd_rate' => $usd > 0 ? $usd : null,
            // تُصفَّر على فاتورة المصاريف مطابقةً لما استُخدم فعلًا في الحساب.
            'commission_rate' => $isExpense ? 0 : (float) ($data['commission_rate'] ?? 0),
            'cbm_rate_usd' => $isExpense ? 0 : (float) ($data['cbm_rate_usd'] ?? 0),
            'foreign_subtotal' => $totals['foreign_subtotal'],
            'landed_subtotal' => $totals['landed_subtotal'],
            'total_cbm' => $totals['total_cbm'],
        ];
    }

    /**
     * ينشئ منتجًا/متغيّرًا من بند «صنف جديد» — يُستدعى عند الترحيل فقط.
     *
     * سعر التكلفة في البطاقة هو التكلفة الشاملة نفسها التي دخل بها المخزون، وحجمُ
     * الوحدة يُحفظ من البند فلا يُعاد قياسه في الشحنة القادمة.
     */
    private function createProductVariant(PurchaseInvoiceItem $item): ProductVariant
    {
        $cost = $this->stockUnitCost($item);
        $sell = $item->new_product_sell_price !== null ? (float) $item->new_product_sell_price : $cost;
        $cbm = (float) $item->cbm_per_unit > 0 ? (float) $item->cbm_per_unit : null;

        $product = $this->products->create([
            'name' => $item->new_product_name,
            'sku' => $this->uniqueSku(),
            'category_id' => $this->defaultCategoryId(),
            'unit_id' => $this->defaultUnitId(),
            'status' => 'active',
            'retail_price' => $sell,
            'cost_price' => $cost,
            'cbm' => $cbm,
        ]);

        $variant = $product->defaultVariant()->firstOrFail();
        $variant->update(['retail_price' => $sell, 'cost_price' => $cost, 'cbm' => $cbm]);

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
            $entry = $this->accounting->postEntry([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'description' => __('فاتورة شراء :n', ['n' => $invoice->number]),
                'source' => 'purchase_invoice',
                'reference_type' => 'purchase_invoice',
                'reference_id' => $invoice->id,
                'idempotency_key' => 'purchase_invoice:'.$invoice->id,
            ], $this->postingLines($invoice));

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

            // إدخال البضاعة للمخزون بالتكلفة (WAC) في المستودع الافتراضي.
            $this->applyStock($invoice);

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

        return DB::transaction(function () use ($invoice, $treasuryId, $amount, $date) {
            // نُخصم من حساب المورد الفرعي نفسه المستخدَم في الترحيل (يبقى رصيده صحيحًا).
            $this->recordPayment($invoice, $treasuryId, $amount, $date);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'payment_status' => $paid + 0.001 >= (float) $invoice->total ? 'paid' : 'partial',
            ]);

            return $invoice;
        });
    }

    /**
     * سداد فاتورة استيراد بمبلغٍ بالدولار وسعرِ صرفِ يومِ الدفع.
     *
     * الدَّين قُيّد على المورد بسعر يوم الفاتورة، ويُدفع اليوم بسعر آخر. فيخرج من
     * الخزينة `usd × سعر اليوم` بينما يُطفأ من ذمّة المورد `usd × سعر الفاتورة` —
     * والفارق ليس دَينًا باقيًا بل **فرقُ صرف** يُسجَّل نتيجةً، وإلا بقيت على
     * المورد قروشٌ لا يعرفها ولا تُسدَّد أبدًا.
     *
     * سند الصرف يحمل المبلغ النقدي كما هو (فرصيدُ الخزينة يبقى صحيحًا)، ويُقيَّد
     * الفرقُ في قيدٍ مستقلّ يُصفّر ذمّة المورد بالضبط.
     */
    public function payForeign(
        PurchaseInvoice $invoice,
        int $treasuryId,
        float $foreignAmount,
        float $paymentRate,
        ?string $date = null,
    ): PurchaseInvoice {
        if (! $invoice->isImport()) {
            throw ValidationException::withMessages([
                'amount' => __('السداد بالعملة الأجنبية للفواتير المستوردة فقط.'),
            ]);
        }
        if ($paymentRate <= 0) {
            throw ValidationException::withMessages(['payment_rate' => __('أدخل سعر صرف يوم الدفع.')]);
        }

        $invoiceRate = (float) $invoice->usd_rate;
        $relieved = round($foreignAmount * $invoiceRate, 2);  // ما يُطفأ من ذمّة المورد
        $cash = round($foreignAmount * $paymentRate, 2);      // ما يخرج من الخزينة
        $difference = round($cash - $relieved, 2);            // موجب = خسارة صرف

        $due = $invoice->balanceDue();
        if ($foreignAmount <= 0 || $relieved > $due + 0.01) {
            throw ValidationException::withMessages([
                'amount' => __('المبلغ يتجاوز المتبقّي (:due).', ['due' => number_format($due, 2)]),
            ]);
        }

        return DB::transaction(function () use ($invoice, $treasuryId, $cash, $relieved, $difference, $date) {
            $this->recordPayment($invoice, $treasuryId, $cash, $date);

            if (abs($difference) >= 0.01) {
                $this->postFxDifference($invoice, $difference, $date);
            }

            // المُسدَّد يُقاس بما أُطفئ من الذمّة لا بما خرج من الخزينة — وإلا لم
            // يصل المتبقّي إلى الصفر أبدًا.
            $paid = round((float) $invoice->amount_paid + $relieved, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'payment_status' => $paid + 0.01 >= (float) $invoice->total ? 'paid' : 'partial',
            ]);

            return $invoice;
        });
    }

    /** سند صرف مُرحّل: مدين ذمم المورد / دائن الخزنة. */
    private function recordPayment(PurchaseInvoice $invoice, int $treasuryId, float $amount, ?string $date): void
    {
        $payable = Account::where('code', $this->payableAccountCode($invoice))->firstOrFail();

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
    }

    /**
     * قيد فرق الصرف. موجب = دفعنا شواكلَ أكثر ممّا قُيّد ⇒ خسارة (مدين الفروق /
     * دائن ذمم المورد، فتُصفَّر الذمّة). سالب = العكس.
     */
    private function postFxDifference(PurchaseInvoice $invoice, float $difference, ?string $date): void
    {
        $amount = abs($difference);
        $payableCode = $this->payableAccountCode($invoice);
        $fxCode = config('accounting.purchasing.fx_difference_account');

        $lines = $difference > 0
            ? [
                ['account_code' => $fxCode, 'debit' => $amount, 'credit' => 0],
                ['account_code' => $payableCode, 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_code' => $payableCode, 'debit' => $amount, 'credit' => 0],
                ['account_code' => $fxCode, 'debit' => 0, 'credit' => $amount],
            ];

        $this->accounting->postEntry([
            'entry_date' => $date ?? now()->toDateString(),
            'description' => __('فرق صرف دفعة فاتورة :n', ['n' => $invoice->number]),
            'source' => 'purchase_invoice_fx',
            'reference_type' => 'purchase_invoice',
            'reference_id' => $invoice->id,
        ], $lines);
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
