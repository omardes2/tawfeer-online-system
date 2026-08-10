<?php

namespace App\Modules\Returns\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\PostingAccountResolver;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Sales\Services\SalesPostingService;
use Illuminate\Support\Facades\DB;

/**
 * الترحيل المحاسبي للمرتجعات (ADR-012f). عند إتمام المرتجع يُنشأ قيدان:
 *
 *  1) عكس الإيراد: مدين «مردودات المبيعات 4030» / دائن حساب مديونية الطلب نفسه المستخدم
 *     عند البيع — فتقلّ مديونية العميل/شركة التوصيل، أو يصبح رصيدها دائنًا (مبلغ مستحقّ
 *     الردّ للعميل) إن كان قد سدّد.
 *  2) عكس التكلفة للبضاعة الصالحة فقط (route=restock): مدين المخزون / دائن تكلفة البضاعة
 *     المباعة، بلقطة تكلفة وقت البيع. البضاعة التالفة (damaged) أو غير العائدة (none) تبقى
 *     تكلفتها مصروفًا في COGS — فهي خسارة فعلية ولا قيمة مخزون لها.
 *
 * idempotent عبر revenue_entry_id على طلب الإرجاع. لا يمسّ النقد: الاسترداد الفعلي سند منفصل.
 */
class ReturnPostingService
{
    private const DOC = 'sales_invoice';

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly PostingAccountResolver $resolver,
        private readonly SalesPostingService $salesPosting,
    ) {}

    public function post(ReturnRequest $request): void
    {
        if ($request->revenue_entry_id !== null) {
            return; // مُرحّل سابقًا.
        }

        $request->loadMissing(['items.orderItem', 'items.variant.product', 'order']);
        $order = $request->order;
        if ($order === null || $request->items->isEmpty()) {
            return;
        }

        $receivableCode = $this->salesPosting->receivableAccountCode($order);
        $returnsCode = $this->resolver->code('sales_returns', null, self::DOC);
        if ($receivableCode === null || $returnsCode === null) {
            return; // إعدادات الترحيل غير مكتملة — لا نكسر العملية.
        }

        DB::transaction(function () use ($request, $receivableCode, $returnsCode) {
            $revenueEntry = $this->postRevenueReversal($request, $receivableCode, $returnsCode);
            $cogsEntry = $this->postCostReversal($request);

            $request->update([
                'revenue_entry_id' => $revenueEntry?->id,
                'cogs_entry_id' => $cogsEntry?->id,
            ]);
        });
    }

    /** مدين مردودات المبيعات / دائن مديونية الطلب — بقيمة البيع الصافية للكميات المرتجعة. */
    private function postRevenueReversal(ReturnRequest $request, string $receivableCode, string $returnsCode): ?JournalEntry
    {
        $amount = 0.0;
        foreach ($request->items as $item) {
            $amount += $this->netUnitPrice($item) * (float) $item->qty;
        }
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('مرتجع مبيعات :n', ['n' => $request->number]),
            'source' => 'sales_return',
            'reference_type' => 'return_request',
            'reference_id' => $request->id,
        ], [
            ['account_code' => $returnsCode, 'debit' => $amount, 'credit' => 0],
            ['account_code' => $receivableCode, 'debit' => 0, 'credit' => $amount],
        ]);
    }

    /** مدين المخزون / دائن تكلفة البضاعة المباعة — للبضاعة الصالحة المُعادة للمخزون فقط. */
    private function postCostReversal(ReturnRequest $request): ?JournalEntry
    {
        $inventoryByAccount = [];
        $cogsByAccount = [];

        foreach ($request->items as $item) {
            if ($item->inventory_route !== 'restock') {
                continue; // التالف/غير العائد: التكلفة تبقى خسارة في COGS.
            }
            $cost = round($this->unitCost($item) * (float) $item->qty, 2);
            if ($cost <= 0) {
                continue;
            }
            $product = $item->variant?->product;
            $invCode = $this->resolver->code('inventory', $product, self::DOC);
            $cogsCode = $this->resolver->code('cogs', $product, self::DOC);
            if ($invCode === null || $cogsCode === null) {
                continue;
            }
            $inventoryByAccount[$invCode] = round(($inventoryByAccount[$invCode] ?? 0) + $cost, 2);
            $cogsByAccount[$cogsCode] = round(($cogsByAccount[$cogsCode] ?? 0) + $cost, 2);
        }

        if (array_sum($inventoryByAccount) <= 0) {
            return null;
        }

        $lines = [];
        foreach ($inventoryByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => $amount, 'credit' => 0];
        }
        foreach ($cogsByAccount as $code => $amount) {
            $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => $amount];
        }

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('تكلفة مرتجع :n', ['n' => $request->number]),
            'source' => 'sales_return_cogs',
            'reference_type' => 'return_request',
            'reference_id' => $request->id,
        ], $lines);
    }

    /** سعر الوحدة الصافي (بعد الخصم) وقت البيع — من بند الطلب، وإلا لقطة سعر بند المرتجع. */
    private function netUnitPrice($item): float
    {
        $orderItem = $item->orderItem;
        if ($orderItem !== null && (float) $orderItem->qty > 0) {
            return round((float) $orderItem->line_total / (float) $orderItem->qty, 4);
        }

        return (float) ($item->unit_price_snapshot ?? 0);
    }

    /** تكلفة الوحدة وقت البيع: لقطة بند المرتجع، وإلا لقطة بند الطلب، وإلا متوسّط التكلفة. */
    private function unitCost($item): float
    {
        return (float) ($item->wholesale_cost_snapshot
            ?? $item->orderItem?->wholesale_cost_snapshot
            ?? $item->variant?->average_cost
            ?? 0);
    }
}
