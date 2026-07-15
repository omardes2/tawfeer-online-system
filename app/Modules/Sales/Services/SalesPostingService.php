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
     * حساب الطرف المدين حسب مصدر البيع:
     *  - طلبات شركة التوصيل (من «انشاء اوردر»، channel ≠ pos): الذمم على شركة التوصيل
     *    «ذمم شركة التوصيل 1050» — حساب واحد بلا حساب عميل لكل طلب (تُسوّى بالتحصيل).
     *  - المبيعات المباشرة (pos): ذمم العميل — حسابه الفرعي تحت «ذمم العملاء 1100»
     *    إن كان عميلًا مسجّلًا، وإلا الحساب العام 1100.
     */
    private function debitCode(Order $order): ?string
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
        $revenueByAccount = [];
        foreach ($order->items as $item) {
            $net = round((float) $item->line_total - (float) $item->discount, 2);
            if ($net === 0.0) {
                continue;
            }
            $code = $this->resolver->code('revenue', $item->variant?->product, self::DOC);
            $revenueByAccount[$code] = round(($revenueByAccount[$code] ?? 0) + $net, 2);
        }

        $shipping = max(0.0, (float) $order->shipping_total);
        $tax = max(0.0, (float) $order->tax_total);
        $creditTotal = round(array_sum($revenueByAccount) + $shipping + $tax, 2);

        $lines = [['account_code' => $debitCode, 'debit' => $creditTotal, 'credit' => 0]];
        foreach ($revenueByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => $amount];
        }
        if ($shipping > 0) {
            $lines[] = ['account_code' => $this->resolver->code('shipping_revenue', null, self::DOC), 'debit' => 0, 'credit' => $shipping];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => $this->resolver->code('tax', null, self::DOC), 'debit' => 0, 'credit' => $tax];
        }

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('مبيعة :n', ['n' => $order->number]),
            'source' => self::DOC,
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ], $lines);
    }

    /** قيد التكلفة: مدين COGS / دائن المخزون (بتكلفة WAC). null إن كانت التكلفة صفرًا. */
    private function postCogs(Order $order): ?JournalEntry
    {
        $cogsByAccount = [];
        $inventoryByAccount = [];
        foreach ($order->items as $item) {
            $wac = (float) ($item->variant?->average_cost ?? $item->variant?->cost_price ?? 0);
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
            return null;
        }

        $lines = [];
        foreach ($cogsByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => $amount, 'credit' => 0];
        }
        foreach ($inventoryByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => $amount];
        }

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('تكلفة مبيعة :n', ['n' => $order->number]),
            'source' => 'sales_cogs',
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ], $lines);
    }
}
