<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Support\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * شحنات الاستيراد (الكونتينرات) — الحسابُ الوسيط مفصَّلًا لكل شحنة.
 *
 * فاتورة البضاعة تُحمّل الحساب الوسيط بتقديرها، وفواتير المصاريف تُطفئه بالفعلي.
 * الرصيد الباقي فرقُ تقدير — والتقديرُ لا يطابق الفعلي أبدًا، فوجودُ الفرق هو
 * القاعدة لا الاستثناء.
 *
 * الإغلاق **يدوي**: يعرض النظام الفرق ونسبته ونسبة المُباع، والقرار للمستخدم.
 * الفرق يُقفل في حساب نتيجة (فروق تقدير) ولا يُعاد به تسعير بضاعةٍ بِيعت —
 * وهذا هو المتَّبع في التكلفة المعيارية.
 */
class ImportShipmentService
{
    /** حدّ التسامح المقترح: فوقه يُنبَّه المستخدم قبل الإغلاق. */
    public const VARIANCE_TOLERANCE_PCT = 3.0;

    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): ImportShipment
    {
        $date = Carbon::parse($data['shipped_at'] ?? now()->toDateString());

        return ImportShipment::create([
            'number' => NumberGenerator::next('import_shipments', 'number', 'CNTR', (int) $date->year),
            'reference' => $data['reference'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'status' => 'open',
            'shipped_at' => $data['shipped_at'] ?? null,
            'arrived_at' => $data['arrived_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(ImportShipment $shipment, array $data): ImportShipment
    {
        $this->assertOpen($shipment);
        $shipment->update([
            'reference' => $data['reference'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'shipped_at' => $data['shipped_at'] ?? null,
            'arrived_at' => $data['arrived_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $shipment;
    }

    /**
     * ملخّص الشحنة — أساس شاشة الإغلاق وتقرير الشحنات المفتوحة.
     *
     * `accrued` ما حمّلته فواتير البضاعة على الحساب الوسيط، و`actual` ما أطفأته
     * فواتير المصاريف، و`variance` الباقي: موجبٌ يعني تقديرًا أعلى من الواقع.
     *
     * `sold_ratio` **تقديري**: المخزون لا يُتتبَّع بالدفعات، فيُقارَن المستلَم في
     * هذه الشحنة بالمتوفّر الآن من أصنافها. رقمٌ إرشادي لقرار الإغلاق لا أكثر.
     *
     * @return array<string, float|int|bool>
     */
    public function summary(ImportShipment $shipment): array
    {
        $goods = $shipment->goodsInvoices()->where('status', 'posted')->with('items')->get();
        $expenses = $shipment->expenseInvoices()->where('status', 'posted')->get();

        $accrued = round($goods->sum(fn (PurchaseInvoice $i) => $i->importDifference()), 2);
        $actual = round((float) $expenses->sum('subtotal'), 2);
        $variance = round($accrued - $actual, 2);

        $received = round((float) $goods->flatMap->items->sum('qty'), 3);
        $onHand = $this->onHandForShipment($goods);

        return [
            'goods_count' => $goods->count(),
            'expenses_count' => $expenses->count(),
            'goods_value' => round((float) $goods->sum('landed_subtotal'), 2),
            'supplier_value' => round((float) $goods->sum('subtotal'), 2),
            'accrued' => $accrued,
            'actual' => $actual,
            'variance' => $variance,
            'variance_pct' => $accrued != 0.0 ? round(abs($variance) / abs($accrued) * 100, 2) : 0.0,
            'over_tolerance' => $accrued != 0.0 && abs($variance) / abs($accrued) * 100 > self::VARIANCE_TOLERANCE_PCT,
            'received_qty' => $received,
            'on_hand_qty' => $onHand,
            'sold_ratio' => $received > 0 ? round(max($received - $onHand, 0) / $received * 100, 1) : 0.0,
        ];
    }

    /**
     * المتوفّر حاليًا من أصناف الشحنة — تقديرٌ لِما لم يُبَع بعد.
     *
     * @param  Collection<int, PurchaseInvoice>  $goods
     */
    private function onHandForShipment($goods): float
    {
        $variantIds = $goods->flatMap->items->pluck('variant_id')->filter()->unique();
        if ($variantIds->isEmpty()) {
            return 0.0;
        }

        return round((float) InventoryStock::whereIn('variant_id', $variantIds)->sum('on_hand'), 3);
    }

    /**
     * إغلاق الشحنة: يُقفل ما تبقّى في الحساب الوسيط بحساب فروق التقدير.
     *
     * تقديرٌ أعلى من الواقع ⇒ مدين الحساب الوسيط / دائن الفروق (تخفيف مصروف).
     * تقديرٌ أقلّ ⇒ العكس. وبفرقٍ دون القرش تُغلق بلا قيد — لا قيدَ بلا أثر.
     */
    public function close(ImportShipment $shipment, ?string $note = null): ImportShipment
    {
        $this->assertOpen($shipment);
        if ($shipment->invoices()->where('status', 'posted')->doesntExist()) {
            throw ValidationException::withMessages([
                'status' => __('لا فواتير مُرحّلة على هذه الشحنة — لا شيء ليُغلق.'),
            ]);
        }

        $summary = $this->summary($shipment);
        $variance = (float) $summary['variance'];

        return DB::transaction(function () use ($shipment, $variance, $note) {
            $entry = abs($variance) >= 0.01 ? $this->postVariance($shipment, $variance) : null;

            $shipment->update([
                'status' => 'closed',
                'variance_amount' => $variance,
                'variance_entry_id' => $entry?->id,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'notes' => $note ?? $shipment->notes,
            ]);

            return $shipment;
        });
    }

    private function postVariance(ImportShipment $shipment, float $variance): JournalEntry
    {
        $cfg = config('accounting.purchasing');
        $amount = abs($variance);
        // موجب = حُمّل على البضاعة أكثر من الواقع ⇒ يُطفأ الحساب الوسيط (مدين)
        // ويُخفَّف المصروف (دائن). سالب = العكس.
        $lines = $variance > 0
            ? [
                ['account_code' => $cfg['import_accrual_account'], 'debit' => $amount, 'credit' => 0],
                ['account_code' => $cfg['import_variance_account'], 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_code' => $cfg['import_variance_account'], 'debit' => $amount, 'credit' => 0],
                ['account_code' => $cfg['import_accrual_account'], 'debit' => 0, 'credit' => $amount],
            ];

        // لا مفتاح idempotency: حارسُ التكرار هو الحالة نفسها (`assertOpen`) —
        // ولو استُخدم مفتاحٌ ثابت لَما أُنشئ قيدٌ جديد بعد إعادة فتحٍ وإغلاق.
        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('فرق تقدير تكاليف شحنة :n', ['n' => $shipment->number]),
            'source' => 'import_shipment_close',
            'reference_type' => 'import_shipment',
            'reference_id' => $shipment->id,
        ], $lines);
    }

    /**
     * إعادة فتح شحنة أُغلقت قبل أوانها: يُعكس قيد الفرق فيعود الحساب الوسيط
     * لحاله. مخرجٌ ضروري — الإغلاق قرارُ بشرٍ يُخطئ، والفواتير قد تتأخّر شهرًا آخر.
     */
    public function reopen(ImportShipment $shipment): ImportShipment
    {
        if ($shipment->isOpen()) {
            throw ValidationException::withMessages(['status' => __('الشحنة مفتوحة أصلًا.')]);
        }

        return DB::transaction(function () use ($shipment) {
            $entry = $shipment->variance_entry_id ? JournalEntry::find($shipment->variance_entry_id) : null;
            if ($entry && $entry->isPosted() && ! $entry->isReversed()) {
                $this->accounting->reverse($entry, [
                    'description' => __('عكس فرق تقدير شحنة :n (إعادة فتح)', ['n' => $shipment->number]),
                ]);
            }

            $shipment->update([
                'status' => 'open',
                'variance_amount' => 0,
                'variance_entry_id' => null,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            return $shipment;
        });
    }

    public function delete(ImportShipment $shipment): void
    {
        if ($shipment->invoices()->exists()) {
            throw ValidationException::withMessages([
                'status' => __('لا تُحذف شحنة مرتبطة بفواتير — افصل الفواتير عنها أولًا.'),
            ]);
        }

        $shipment->delete();
    }

    private function assertOpen(ImportShipment $shipment): void
    {
        if (! $shipment->isOpen()) {
            throw ValidationException::withMessages([
                'status' => __('الشحنة مُغلقة — أعِد فتحها قبل التعديل.'),
            ]);
        }
    }
}
