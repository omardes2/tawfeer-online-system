<?php

namespace App\Modules\Purchasing\Console;

use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دمج مورّدٍ مكرّر في آخر — **يعرض ولا يكتب** إلّا بـ`--apply`.
 *
 * المورد الواحد يُدخَل مرّتين بفارق حرف («بضاعه» و«بضاعة»)، فيُفتح له حسابان
 * فرعيّان وتتوزّع حركاته بينهما، ويظهر في ميزان المراجعة مرّتين.
 *
 * والدمج لا ينقل القيود: ينقل الرصيد بقيد إعادة تصنيف، ويُسند المستندات، ويُعطّل
 * الحساب القديم. راجع `SupplierService::merge` لِمَ.
 *
 * وعرضٌ قبل الكتابة لأن العملية تمسّ مالًا وتاريخًا: من يقرأ الجدول قبل التنفيذ
 * يكتشف أنه اختار الاتجاه معكوسًا.
 */
class MergeSuppliersCommand extends Command
{
    protected $signature = 'purchasing:merge-suppliers
                            {source : المورد المكرّر الذي يُدمَج ويُعطَّل — اسم أو رمز أو معرّف}
                            {target : المورد الباقي الذي يُنقل إليه — مثله}
                            {--note= : سببٌ يُكتب في وصف قيد إعادة التصنيف}
                            {--apply : تنفيذ الدمج فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'دمج مورّد مكرّر في آخر: نقل الرصيد بقيد إعادة تصنيف وإسناد المستندات';

    public function handle(SupplierService $suppliers): int
    {
        $source = $this->resolve((string) $this->argument('source'), 'المصدر');
        $target = $this->resolve((string) $this->argument('target'), 'الهدف');

        if (! $source || ! $target) {
            return self::FAILURE;
        }

        if ($source->id === $target->id) {
            $this->error('المورد نفسه لا يُدمج في نفسه.');

            return self::FAILURE;
        }

        $this->preview($source, $target, $suppliers);

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('عرضٌ فقط — أضف --apply للتنفيذ.');

            return self::SUCCESS;
        }

        // الاتجاه لا يُستدرك بعد التنفيذ إلّا بعكس قيدٍ وإعادة إسناد: يُسأل عنه.
        if (! $this->confirm('يُدمَج «'.$source->name.'» في «'.$target->name.'» ويُعطَّل. أتابع؟', false)) {
            $this->line('أُلغي.');

            return self::SUCCESS;
        }

        $result = $suppliers->merge($source, $target, $this->option('note'));

        $this->line('');
        $this->info('تمّ الدمج.');
        $this->table(['الجدول', 'مستندات نُقلت'], collect($result['moved'])
            ->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line('قيد إعادة التصنيف: '.($result['entry'] ?? 'لا حاجة له — الرصيد صفر'));
        $this->info('رصيد «'.$target->name.'» بعد الدمج: '.number_format($result['balance'], 2));

        return self::SUCCESS;
    }

    private function preview(Supplier $source, Supplier $target, SupplierService $suppliers): void
    {
        $accounting = app(AccountingService::class);

        $this->line('');
        $this->table(['', 'المصدر (يُدمَج ويُعطَّل)', 'الهدف (يبقى)'], [
            ['الاسم', $source->name, $target->name],
            ['الرمز', $source->code, $target->code],
            ['الحساب', $source->glAccount()->value('code') ?? '—', $target->glAccount()->value('code') ?? '—'],
            ['الرصيد', $this->num($suppliers->ledgerBalance($source)), $this->num($suppliers->ledgerBalance($target))],
        ]);

        $rows = [];
        $total = 0;
        foreach (['purchase_invoices' => 'فواتير شراء', 'financial_vouchers' => 'سندات',
            'purchase_orders' => 'أوامر شراء', 'supplier_returns' => 'مرتجعات',
            'import_shipments' => 'شحنات استيراد', 'supplier_contacts' => 'جهات اتصال'] as $table => $label) {
            $n = DB::table($table)->where('supplier_id', $source->id)->count();
            $total += $n;
            $rows[] = [$label, $n];
        }

        $this->line('');
        $this->line('  مستندات ستُسنَد للهدف:');
        $this->table(['النوع', 'العدد'], $rows);

        $balance = round($accounting->accountBalance(
            $source->glAccount()->first() ?? $target->glAccount()->firstOrFail(),
        ), 2);

        $this->line('');
        $this->line('  ما سيحدث:');
        $this->line('   • قيد إعادة تصنيف ينقل '.$this->num(abs($balance)).' من حساب المصدر إلى حساب الهدف');
        $this->line('   • '.$total.' مستندًا يُسنَد للهدف');
        $this->line('   • حساب المصدر **يُعطَّل ولا يُحذف** — يحمل قيودًا مُرحّلة');
        $this->line('   • كشف الهدف يعرض بعدها حركات الحسابين معًا');
        $this->line('   • **لا يُعدَّل ولا يُحذف أيّ قيد مُرحّل**');
    }

    private function resolve(string $key, string $role): ?Supplier
    {
        $matches = Supplier::query()
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->orWhere('code', $key)
            ->orWhere('name', 'like', '%'.$key.'%')
            ->orderBy('id')->get();

        if ($matches->isEmpty()) {
            $this->error('لا مورد '.$role.' بالاسم أو الرمز «'.$key.'».');

            return null;
        }

        // الاسمان متشابهان بحرف — فالمطابقة المتعدّدة هي القاعدة هنا لا الاستثناء،
        // ولا يجوز اختيار أحدهما آليًّا: الخطأ يُعطّل المورد الحيّ.
        if ($matches->count() > 1) {
            $this->error('«'.$key.'» يطابق '.$matches->count().' موردين — حدّد بالرمز أو المعرّف:');
            $this->table(['المعرّف', 'الرمز', 'الاسم', 'الحساب'], $this->rows($matches));

            return null;
        }

        return $matches->first();
    }

    /**
     * @param  Collection<int, Supplier>  $matches
     * @return array<int, array<int, string>>
     */
    private function rows(Collection $matches): array
    {
        return $matches->map(fn (Supplier $s) => [
            (string) $s->id, (string) $s->code, $s->name,
            $s->glAccount()->value('code') ?? '—',
        ])->all();
    }

    private function num(float $value): string
    {
        return number_format($value, 2);
    }
}
