<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Support\NumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك القيد المزدوج (ADR-029/016، BR-ACC). كل قيد متوازن؛ المُرحّل غير قابل للتعديل؛
 * التصحيح بعكس لا حذف؛ الأرصدة تُشتقّ من journal_lines دائمًا (لا رصيد مخزَّن).
 */
class AccountingService
{
    /**
     * إنشاء قيد مسودّة متوازن.
     *
     * @param  array<int, array{account_code:string, debit?:float, credit?:float, description?:string}>  $lines
     */
    public function createEntry(array $data, array $lines): JournalEntry
    {
        $prepared = $this->prepareLines($lines);
        $this->assertBalanced($prepared);

        $date = Carbon::parse($data['entry_date'] ?? now()->toDateString());
        $fiscalYear = $this->resolveFiscalYear($date);
        $period = $this->resolvePeriod($fiscalYear, $date);

        return DB::transaction(function () use ($data, $prepared, $date, $fiscalYear, $period) {
            $entry = JournalEntry::create([
                'number' => NumberGenerator::next('journal_entries', 'number', 'JE', (int) $date->year),
                'fiscal_year_id' => $fiscalYear->id,
                'period_id' => $period?->id,
                'entry_date' => $date->toDateString(),
                'description' => $data['description'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'status' => 'draft',
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reverses_entry_id' => $data['reverses_entry_id'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($prepared as $i => $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'line_no' => $i + 1,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency' => $line['currency'] ?? null,
                    'description' => $line['description'] ?? null,
                    'created_at' => now(),
                ]);
            }

            return $entry;
        });
    }

    /** ترحيل قيد مسودّة (draft → posted). المُرحّل غير قابل للتعديل بعدها. */
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('لا يمكن ترحيل قيد بحالة :status.', ['status' => $entry->status])]);
        }
        $this->assertOpen($entry->fiscalYear, $entry->period);

        $entry->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => auth()->id()]);

        return $entry;
    }

    /** إنشاء وترحيل معًا (للأحداث/النظام). */
    public function postEntry(array $data, array $lines): JournalEntry
    {
        return $this->post($this->createEntry($data, $lines));
    }

    /**
     * عكس قيد مُرحّل بقيد عاكس جديد (لا حذف/تعديل للأصل — المتطلّب 8، BR-ACC-09).
     */
    public function reverse(JournalEntry $entry, array $opts = []): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw ValidationException::withMessages(['status' => __('لا يمكن عكس قيد غير مُرحّل.')]);
        }
        if ($entry->isReversed()) {
            throw ValidationException::withMessages(['status' => __('القيد معكوس مسبقًا.')]);
        }

        $entry->loadMissing('lines.account');
        $lines = $entry->lines->map(fn ($l) => [
            'account_code' => $l->account->code,
            'debit' => (float) $l->credit, // مقلوب
            'credit' => (float) $l->debit,
            'description' => $l->description,
        ])->all();

        return $this->postEntry([
            'entry_date' => $opts['date'] ?? now()->toDateString(),
            'description' => $opts['description'] ?? __('عكس قيد :number', ['number' => $entry->number]),
            'source' => 'system',
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'reverses_entry_id' => $entry->id,
        ], $lines);
    }

    /**
     * تحديث سطور قيد قائم في مكانه (نفس القيد ورقمه) — يستبدل السطور بأخرى متوازنة جديدة.
     * يُستخدم عند تعديل فاتورة مبيعات: يُحدَّث قيدها الموجود بدل إنشاء قيد جديد (قرار إداري).
     * يبقى تاريخ القيد ورقمه كما هما؛ تتغيّر السطور والمبالغ فقط.
     *
     * @param  array<int, array{account_code:string, debit?:float, credit?:float, description?:string}>  $lines
     */
    public function replaceLines(JournalEntry $entry, array $lines): JournalEntry
    {
        $prepared = $this->prepareLines($lines);
        $this->assertBalanced($prepared);

        return DB::transaction(function () use ($entry, $prepared) {
            // حذف السطور القديمة عبر مُنشئ الاستعلام (لا يُطلق أحداث الموديل append-only).
            $entry->lines()->delete();

            foreach ($prepared as $i => $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'line_no' => $i + 1,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency' => $line['currency'] ?? null,
                    'description' => $line['description'] ?? null,
                    'created_at' => now(),
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * حذف قيد (وسطوره) نهائيًا — يُستخدم حصرًا عند حذف الوثيقة المرتبطة (فاتورة مبيعات)،
     * فيُحذف قيدها المحاسبي تلقائيًا بدل عكسه (قرار إداري). عملية محكومة عبر علم مؤقّت.
     */
    public function deleteEntry(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry->lines()->delete(); // مُنشئ استعلام — لا أحداث موديل.

            JournalEntry::$allowPostedDeletion = true;
            try {
                $entry->delete();
            } finally {
                JournalEntry::$allowPostedDeletion = false;
            }
        });
    }

    /**
     * توليد قيد من حدث مالي (الخطّاف — BR-ACC-01/11، ADR-016). خريطة الحساب من الإعداد.
     * إضافة نوع حدث = إدخال في config/accounting.php + حسابات مزروعة، دون تعديل الكود.
     */
    public function recordEvent(string $type, array $context): ?JournalEntry
    {
        $map = config("accounting.events.{$type}");
        if (! $map) {
            return null; // غير مُهيّأ — لا قيد (آمن)
        }

        $amount = round((float) ($context['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->postEntry([
            'entry_date' => $context['date'] ?? now()->toDateString(),
            'description' => $context['description'] ?? ($map['description'] ?? $type),
            'source' => 'event',
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
        ], [
            ['account_code' => $map['debit'], 'debit' => $amount, 'credit' => 0],
            ['account_code' => $map['credit'], 'debit' => 0, 'credit' => $amount],
        ]);
    }

    /** رصيد حساب مشتقّ من الدفتر (POSTED فقط) — بطبيعته الطبيعية (المتطلّب 4). */
    public function accountBalance(Account $account, array $filters = []): float
    {
        // رصيد الحساب يشمل حساباته الفرعية (حساب مراقبة): مثلًا «ذمم الموردين» =
        // مجموع أرصدة حسابات الموردين الفرعية. الحساب الطرفي لا فروع له فلا فرق.
        $accountIds = $this->accountWithDescendantIds($account);

        $query = JournalLine::query()->whereIn('account_id', $accountIds)
            ->whereHas('entry', function ($q) use ($filters) {
                $q->where('status', 'posted');
                if (! empty($filters['fiscal_year_id'])) {
                    $q->where('fiscal_year_id', $filters['fiscal_year_id']);
                }
            });

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /** معرّف الحساب مع كل فروعه (تفكيك تكراري) — لتجميع رصيد حساب المراقبة. */
    private function accountWithDescendantIds(Account $account): array
    {
        $ids = [$account->id];

        foreach (Account::where('parent_id', $account->id)->get(['id', 'parent_id']) as $child) {
            $ids = array_merge($ids, $this->accountWithDescendantIds($child));
        }

        return $ids;
    }

    /** ميزان المراجعة: لكل حساب قابل للترحيل مدينه ودائنه — مجموع المدين = مجموع الدائن. */
    public function trialBalance(?FiscalYear $fiscalYear = null): Collection
    {
        return Account::query()->postable()->orderBy('code')->get()->map(function (Account $account) use ($fiscalYear) {
            $query = $account->lines()->whereHas('entry', function ($q) use ($fiscalYear) {
                $q->where('status', 'posted');
                if ($fiscalYear) {
                    $q->where('fiscal_year_id', $fiscalYear->id);
                }
            });
            $debit = (float) (clone $query)->sum('debit');
            $credit = (float) (clone $query)->sum('credit');

            return [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'type' => $account->type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'balance' => round($account->isDebitNormal() ? $debit - $credit : $credit - $debit, 2),
            ];
        })->filter(fn ($row) => $row['debit'] != 0.0 || $row['credit'] != 0.0)->values();
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    private function prepareLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => __('القيد يتطلّب سطرين على الأقل.')]);
        }

        return collect($lines)->map(function ($line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(['lines' => __('كل سطر يجب أن يكون مدينًا أو دائنًا (لا كلاهما ولا صفر).')]);
            }

            $account = Account::where('code', $line['account_code'])->first();
            if (! $account || ! $account->is_postable) {
                throw ValidationException::withMessages(['lines' => __('حساب غير صالح للترحيل: :code', ['code' => $line['account_code'] ?? ''])]);
            }

            return [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'currency' => $line['currency'] ?? null,
                'description' => $line['description'] ?? null,
            ];
        })->all();
    }

    private function assertBalanced(array $lines): void
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if ($debit !== $credit || $debit <= 0) {
            throw ValidationException::withMessages(['lines' => __('القيد غير متوازن: المدين :d والدائن :c.', ['d' => $debit, 'c' => $credit])]);
        }
    }

    private function resolveFiscalYear(Carbon $date): FiscalYear
    {
        $year = FiscalYear::where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())->first();

        if (! $year) {
            throw ValidationException::withMessages(['entry_date' => __('لا توجد سنة مالية للتاريخ المحدّد.')]);
        }

        return $year;
    }

    private function resolvePeriod(FiscalYear $year, Carbon $date): ?AccountingPeriod
    {
        return $year->periods()
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())->first();
    }

    private function assertOpen(FiscalYear $year, ?AccountingPeriod $period): void
    {
        if (! $year->isOpen()) {
            throw ValidationException::withMessages(['fiscal_year' => __('السنة المالية مقفلة.')]);
        }
        if ($period && ! $period->isOpen()) {
            throw ValidationException::withMessages(['period' => __('الفترة المحاسبية مقفلة.')]);
        }
    }
}
