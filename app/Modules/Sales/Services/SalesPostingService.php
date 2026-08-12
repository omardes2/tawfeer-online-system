<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\PostingAccountResolver;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل المبيعات محاسبيًا تلقائيًا (BR-ACC / Posting Setup). عند تأكيد الطلب يُنشأ قيدان:
 *   1) الإيراد: مدين ذمم العملاء (أو الصندوق للبيع المباشر) / دائن الإيراد [+ شحن + ضريبة].
 *   2) التكلفة: مدين تكلفة البضاعة المباعة / دائن المخزون (بتكلفة WAC).
 * الحسابات تُحلّ بأولوية: المنتج → فئته → إعدادات الترحيل. idempotent عبر revenue_entry_id.
 */
class SalesPostingService
{
    private const DOC = 'sales_invoice';

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly PostingAccountResolver $resolver,
    ) {}

    public function post(Order $order): void
    {
        if ($order->revenue_entry_id !== null) {
            return; // مُرحّل سابقًا.
        }

        $order->loadMissing('items.variant.product', 'customer');
        if ($order->items->isEmpty()) {
            return;
        }

        $debitCode = $this->debitCode($order);
        if ($debitCode === null) {
            return; // إعدادات الترحيل غير مكتملة — لا نكسر البيع؛ يُعاد الترحيل بعد الضبط.
        }

        DB::transaction(function () use ($order, $debitCode) {
            $order->update([
                'revenue_entry_id' => $this->postRevenue($order, $debitCode)->id,
                'cogs_entry_id' => optional($this->postCogs($order))->id,
            ]);
        });
    }

    /**
     * إعادة ترحيل طلب مُرحّل بعد تعديل أصنافه/مبالغه: يُحدَّث القيد الموجود في مكانه (نفس رقمه)
     * بدل إنشاء قيد جديد. يُعاد بناء سطور الإيراد والتكلفة من الحالة الحالية للطلب. إن لم يكن
     * الطلب مُرحّلًا بعد فيُرحَّل من جديد. idempotent.
     */
    public function repost(Order $order): void
    {
        if ($order->revenue_entry_id === null) {
            $this->post($order);

            return;
        }

        $order->loadMissing('items.variant.product', 'customer');

        $debitCode = $this->debitCode($order);
        if ($debitCode === null) {
            return;
        }

        DB::transaction(function () use ($order, $debitCode) {
            // 1) قيد الإيراد: يُحدَّث في مكانه بالسطور الجديدة.
            $revenueEntry = JournalEntry::find($order->revenue_entry_id);
            if ($revenueEntry) {
                $this->accounting->replaceLines($revenueEntry, $this->revenueLines($order, $debitCode));
            }

            // 2) قيد التكلفة: يُحدَّث/يُنشأ/يُحذف حسب وجود تكلفة الآن.
            $cogsLines = $this->cogsLines($order);
            $cogsEntry = $order->cogs_entry_id ? JournalEntry::find($order->cogs_entry_id) : null;

            if ($cogsLines === []) {
                if ($cogsEntry) {
                    $this->accounting->deleteEntry($cogsEntry);
                    $order->update(['cogs_entry_id' => null]);
                }
            } elseif ($cogsEntry) {
                $this->accounting->replaceLines($cogsEntry, $cogsLines);
            } else {
                $order->update(['cogs_entry_id' => $this->accounting->postEntry($this->cogsMeta($order), $cogsLines)->id]);
            }
        });
    }

    /**
     * حذف القيد المحاسبي المرتبط بالطلب (الإيراد + التكلفة) نهائيًا وتصفير مراجعه — يُستخدم عند
     * حذف الفاتورة، فيُحذف قيدها تلقائيًا بدل عكسه (قرار إداري). idempotent.
     */
    public function unpost(Order $order): void
    {
        foreach (['revenue_entry_id', 'cogs_entry_id'] as $column) {
            if ($order->{$column} === null) {
                continue;
            }
            $entry = JournalEntry::find($order->{$column});
            if ($entry) {
                $this->accounting->deleteEntry($entry);
            }
        }

        $order->update(['revenue_entry_id' => null, 'cogs_entry_id' => null]);
    }

    /**
     * حساب الطرف المدين حسب مصدر البيع:
     *  - طلبات شركة التوصيل (من «انشاء اوردر»، channel ≠ pos): الذمم على شركة التوصيل
     *    «ذمم شركة التوصيل 1050» — حساب واحد بلا حساب عميل لكل طلب (تُسوّى بالتحصيل).
     *  - المبيعات المباشرة (pos): ذمم العميل — حسابه الفرعي تحت «ذمم العملاء 1100»
     *    إن كان عميلًا مسجّلًا، وإلا الحساب العام 1100.
     */
    private function debitCode(Order $order): ?string
    {
        return $this->receivableAccountCode($order);
    }

    /**
     * رمز حساب المديونية الذي يُقيَّد عليه الطلب (نفسه يُستخدم عند التحصيل لإقفاله):
     * طلبات التوصيل → ذمم شركة التوصيل (1050)؛ المبيعات المباشرة → حساب العميل الفرعي
     * تحت «ذمم العملاء 1100» (أو الحساب العام 1100 لعميل غير مسجّل).
     */
    /**
     * المبلغ الذي يدخل الدفاتر فعلًا = إجمالي الطلب − رسوم التوصيل.
     *
     * رسوم التوصيل تمرّ من العميل إلى شركة التوصيل مباشرةً (المندوب يقبضها ويحتفظ بها)،
     * فلا تُقيَّد لا في قيد البيع ولا في قيد التحصيل. المصدر الوحيد لهذا الحساب حتى تبقى
     * الذمّة على شركة التوصيل مطابقة تمامًا لما يُورَّد إلينا.
     */
    public function bookableTotal(Order $order): float
    {
        return round(max((float) $order->total - (float) $order->shipping_total, 0), 2);
    }

    public function receivableAccountCode(Order $order): ?string
    {
        if ($order->channel !== 'pos') {
            return $this->resolver->code('cod_receivable', null, self::DOC)
                ?? config('accounting.sales.cod_receivable', '1050');
        }

        if ($order->customer_id && ($customer = $order->customer)) {
            $account = $customer->glAccount()->first()
                ?: app(CustomerService::class)->ensureLedgerAccount($customer);
            if ($account) {
                return $account->code;
            }
        }

        return $this->resolver->code('receivable', null, self::DOC);
    }

    /** قيد الإيراد: مدين (نقدي/ذمم) = الإجمالي / دائن الإيراد لكل حساب منتج [+ شحن + ضريبة]. */
    private function postRevenue(Order $order, string $debitCode): JournalEntry
    {
        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('مبيعة :n', ['n' => $order->number]),
            'source' => self::DOC,
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ], $this->revenueLines($order, $debitCode));
    }

    /** سطور قيد الإيراد: مدين (نقدي/ذمم) = الإجمالي / دائن الإيراد لكل حساب منتج [+ شحن + ضريبة]. */
    private function revenueLines(Order $order, string $debitCode): array
    {
        $revenueByAccount = [];
        foreach ($order->items as $item) {
            // line_total مخصوم أصلًا ((qty × unit_price) − discount) — لا يُطرح الخصم ثانيةً،
            // وإلا نقصت الذمم والإيراد بمقدار الخصم واختلّ رصيد العميل/شركة التوصيل.
            $net = round((float) $item->line_total, 2);
            if ($net === 0.0) {
                continue;
            }
            $code = $this->resolver->code('revenue', $item->variant?->product, self::DOC);
            $revenueByAccount[$code] = round(($revenueByAccount[$code] ?? 0) + $net, 2);
        }

        // رسوم التوصيل **خارج الدفاتر** (قرار المالك): المندوب يقبضها من العميل ويحتفظ بها
        // أجرةً له، فلا تُقيَّد إيرادًا ولا مصروفًا ولا ذمّة — الذمّة على شركة التوصيل هي
        // قيمة البضاعة وحدها، وهي نفسها ما يُورَّد لنا لاحقًا. راجع bookableTotal().
        $tax = max(0.0, (float) $order->tax_total);
        $creditTotal = round(array_sum($revenueByAccount) + $tax, 2);

        $lines = [['account_code' => $debitCode, 'debit' => $creditTotal, 'credit' => 0]];
        foreach ($revenueByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => $amount];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => $this->resolver->code('tax', null, self::DOC), 'debit' => 0, 'credit' => $tax];
        }

        return $lines;
    }

    /** قيد التكلفة: مدين COGS / دائن المخزون (بتكلفة WAC). null إن كانت التكلفة صفرًا. */
    private function postCogs(Order $order): ?JournalEntry
    {
        $lines = $this->cogsLines($order);
        if ($lines === []) {
            return null;
        }

        return $this->accounting->postEntry($this->cogsMeta($order), $lines);
    }

    /** سطور قيد التكلفة: مدين COGS / دائن المخزون (بتكلفة WAC). [] إن كانت التكلفة صفرًا. */
    private function cogsLines(Order $order): array
    {
        $cogsByAccount = [];
        $inventoryByAccount = [];
        foreach ($order->items as $item) {
            // لقطة التكلفة وقت البيع أولًا: تُبقي قيد التكلفة ثابتًا عند إعادة الترحيل
            // (تعديل الطلب لاحقًا) وتوافق تقارير الربح وإرجاع المخزون على نفس الأساس.
            $wac = (float) ($item->wholesale_cost_snapshot ?? $item->variant?->average_cost ?? $item->variant?->cost_price ?? 0);
            $cost = round((float) $item->qty * $wac, 2);
            if ($cost <= 0) {
                continue;
            }
            $product = $item->variant?->product;
            $cogsCode = $this->resolver->code('cogs', $product, self::DOC);
            $invCode = $this->resolver->code('inventory', $product, self::DOC);
            $cogsByAccount[$cogsCode] = round(($cogsByAccount[$cogsCode] ?? 0) + $cost, 2);
            $inventoryByAccount[$invCode] = round(($inventoryByAccount[$invCode] ?? 0) + $cost, 2);
        }

        if (array_sum($cogsByAccount) <= 0) {
            return [];
        }

        $lines = [];
        foreach ($cogsByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => $amount, 'credit' => 0];
        }
        foreach ($inventoryByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => $amount];
        }

        return $lines;
    }

    /** بيانات ترويسة قيد التكلفة (مرجع الطلب). */
    private function cogsMeta(Order $order): array
    {
        return [
            'entry_date' => now()->toDateString(),
            'description' => __('تكلفة مبيعة :n', ['n' => $order->number]),
            'source' => 'sales_cogs',
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ];
    }
}
