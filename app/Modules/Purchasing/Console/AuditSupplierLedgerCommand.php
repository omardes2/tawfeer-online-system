<?php

namespace App\Modules\Purchasing\Console;

use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * مطابقة حساب المورد: المستندات مقابل دفتر الأستاذ — **قراءة فقط**.
 *
 * ## العطب الذي تبحث عنه
 *
 * بطاقات صفحة المورد تُبنى من **ثلاثة مصادر مختلفة**:
 *
 *   إجمالي المشتريات ← `purchase_invoices.total` (المُرحّلة وحدها)
 *   المدفوعات        ← `financial_vouchers` بـ`supplier_id`
 *   الرصيد المتبقّي   ← **دفتر الأستاذ**
 *
 * والفرق بين الثلاثة يُعرض «تسويات» بلا تفصيل. فسندٌ يحمل `supplier_id` لكنه
 * مُقيَّد على حساب الذمم الأمّ (2010) بدل حساب المورد الفرعيّ يُعدّ في
 * «المدفوعات» **ولا يظهر في الرصيد** — ويُبتلَع الفرق صامتًا.
 *
 * وهذا الأمر يفكّ ذلك الصمت: يعرض كل مصدرٍ على حدة، ويسمّي المستندات التي
 * تفترق فيها الروايتان.
 *
 * **لا يكتب حرفًا.** التصحيح قرارٌ بشري يُتّخذ بعد قراءة الأرقام لا قبلها.
 */
class AuditSupplierLedgerCommand extends Command
{
    protected $signature = 'purchasing:audit-supplier-ledger
                            {supplier? : اسم المورد أو رمزه أو معرّفه — بدونه كل الموردين}
                            {--tolerance=0.01 : الفرق الذي يُتجاوَز عنه}';

    protected $description = 'مطابقة حساب المورد بين المستندات ودفتر الأستاذ (قراءة فقط)';

    public function handle(SupplierService $suppliers): int
    {
        $targets = $this->targets();

        if ($targets->isEmpty()) {
            $this->error('لا مورد مطابق.');

            return self::FAILURE;
        }

        $tolerance = max(0.001, (float) $this->option('tolerance'));
        $mismatched = 0;

        foreach ($targets as $supplier) {
            if ($this->report($supplier, $suppliers, $tolerance)) {
                $mismatched++;
            }
        }

        $this->line('');
        $this->info($mismatched === 0
            ? 'كل الحسابات مطابِقة — المستندات والدفتر يقولان الرقم نفسه.'
            : 'حسابات لا تُطابق: '.$mismatched.' من '.$targets->count().'.');
        $this->line('قراءة فقط — لم يُكتب شيء.');

        return self::SUCCESS;
    }

    /** يعرض حساب مورد، ويُعيد true إن كان فيه فرق. */
    private function report(Supplier $supplier, SupplierService $suppliers, float $tolerance): bool
    {
        $account = $supplier->glAccount()->first();

        $opening = round((float) $supplier->opening_balance, 2);
        $invoiced = round((float) PurchaseInvoice::where('supplier_id', $supplier->id)
            ->where('status', 'posted')->sum('total'), 2);
        $paid = round((float) FinancialVoucher::where('supplier_id', $supplier->id)
            ->where('kind', 'payment')->where('status', 'posted')->sum('amount'), 2);

        $ledger = round($suppliers->ledgerBalance($supplier), 2);
        $documents = round($opening + $invoiced - $paid, 2);
        $gap = round($documents - $ledger, 2);

        $this->line('');
        $this->line(str_repeat('─', 72));
        $this->info($supplier->name.'  ·  الرمز '.$supplier->code
            .'  ·  الحساب '.($account?->code ?? 'لا حساب فرعيّ ⚠'));

        $this->table(['البند', 'القيمة', 'ملاحظة'], [
            ['رصيد افتتاحي', $this->num($opening), $this->openingNote($opening, $supplier)],
            ['+ مشتريات مُرحّلة', $this->num($invoiced), ''],
            ['− دفعات مُرحّلة', $this->num($paid), ''],
            ['= حسب المستندات', $this->num($documents), ''],
            ['حسب دفتر الأستاذ', $this->num($ledger), 'وهو ما تعرضه بطاقة «الرصيد المتبقّي»'],
            ['الفرق', $this->num($gap), abs($gap) < $tolerance ? '✓ مطابق' : '⚠ يحتاج تفسيرًا'],
        ]);

        if ($account) {
            $this->ledgerBySource($account->id);
        }

        $this->excludedInvoices($supplier);
        $this->strayVouchers($supplier, $account?->id);

        return abs($gap) >= $tolerance;
    }

    /**
     * سطور الدفتر مجمّعةً حسب مصدر القيد — فيُقرأ الفرق: قيدُ فرق صرفٍ أو
     * تسويةٍ يدوية يظهر هنا باسمه بدل أن يُعرض «تسويات» بلا تفسير.
     */
    private function ledgerBySource(int $accountId): void
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $accountId)
            ->where('je.status', 'posted')
            ->groupBy('je.source')
            ->selectRaw('je.source, COUNT(*) as n, SUM(jl.credit - jl.debit) as net')
            ->orderByDesc('n')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line('  سطور الدفتر حسب المصدر:');
        $this->table(['المصدر', 'عدد السطور', 'الصافي (دائن − مدين)'], $rows->map(fn ($r) => [
            $this->sourceLabel($r->source),
            $r->n,
            $this->num((float) $r->net),
        ])->all());
    }

    /**
     * الفواتير التي **لا** تدخل «إجمالي المشتريات» — المعكوسة والملغاة والمحذوفة
     * والمسودّات. أثرها في الدفتر صفرٌ إن عُكست كما ينبغي، فوجودُها هنا يفسّر
     * لماذا يخالف مجموعُ الفواتير على الشاشة ما يتذكّره المستخدم.
     */
    private function excludedInvoices(Supplier $supplier): void
    {
        $rows = PurchaseInvoice::withTrashed()
            ->where('supplier_id', $supplier->id)
            ->where(fn ($q) => $q->where('status', '!=', 'posted')->orWhereNotNull('deleted_at'))
            ->orderBy('id')
            ->get(['number', 'status', 'total', 'deleted_at', 'reversal_entry_id']);

        if ($rows->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line('  فواتير خارج «إجمالي المشتريات»:');
        $this->table(['الرقم', 'الحالة', 'القيمة', 'محذوفة', 'قيد العكس'], $rows->map(fn ($i) => [
            $i->number,
            $i->status,
            $this->num((float) $i->total),
            $i->deleted_at ? 'نعم' : '—',
            $i->reversal_entry_id ?: '—',
        ])->all());
    }

    /**
     * سندات تحمل اسم المورد ولم تُقيَّد على حسابه الفرعيّ.
     *
     * هذه بالضبط السندات التي تُعدّ في بطاقة «المدفوعات» ولا تُنقص «الرصيد
     * المتبقّي» — فيبدو المورد مدينًا بما سُدِّد له.
     */
    private function strayVouchers(Supplier $supplier, ?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $vouchers = FinancialVoucher::where('supplier_id', $supplier->id)
            ->where('kind', 'payment')->where('status', 'posted')
            ->get(['id', 'number', 'voucher_date', 'amount', 'journal_entry_id']);

        $stray = $vouchers->filter(function (FinancialVoucher $v) use ($accountId) {
            if ($v->journal_entry_id === null) {
                return true;
            }

            return ! DB::table('journal_lines')
                ->where('journal_entry_id', $v->journal_entry_id)
                ->where('account_id', $accountId)
                ->exists();
        });

        if ($stray->isEmpty()) {
            return;
        }

        $this->line('');
        $this->warn('  ⚠ سندات لم تُقيَّد على حساب المورد الفرعيّ — تُعدّ في «المدفوعات» ولا تُنقص الرصيد:');
        $this->table(['السند', 'التاريخ', 'المبلغ', 'قيده'], $stray->map(fn ($v) => [
            $v->number,
            $v->voucher_date?->format('Y-m-d') ?? '—',
            $this->num((float) $v->amount),
            $v->journal_entry_id ?: 'بلا قيد ⚠',
        ])->all());

        $this->line('  مجموعها: '.$this->num((float) $stray->sum('amount')));
    }

    private function openingNote(float $opening, Supplier $supplier): string
    {
        if (abs($opening) < 0.01) {
            return '';
        }

        $side = $opening > 0 ? 'موجب ⇒ نحن مدينون له' : 'سالب ⇒ دفعنا له مقدَّمًا';

        return $side.($supplier->opening_entry_id ? '' : ' · بلا قيد ⚠');
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            'purchase_invoice' => 'فاتورة شراء',
            'purchase_invoice_fx' => 'فرق صرف',
            'voucher' => 'سند',
            'supplier_opening', 'opening_balance' => 'رصيد افتتاحي',
            'system' => 'عكس/نظام',
            'manual' => 'قيد يدوي',
            default => (string) ($source ?? '—'),
        };
    }

    /** @return Collection<int, Supplier> */
    private function targets(): Collection
    {
        $key = $this->argument('supplier');

        if ($key === null) {
            return Supplier::orderBy('name')->get();
        }

        return Supplier::query()
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->orWhere('code', $key)
            ->orWhere('name', 'like', '%'.$key.'%')
            ->orderBy('name')->get();
    }

    private function num(float $value): string
    {
        return number_format($value, 2);
    }
}
