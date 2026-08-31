<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Events\VoucherRevised;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خدمة السندات المالية (Phase 7.1): قبض/صرف/مصروف/إيراد آخر/تحويل.
 *
 * كل ترحيل يُنشئ **قيدًا متوازنًا عبر AccountingService** (لا مساس مباشر بالأرصدة). الترحيل
 * idempotent (لا تكرار عند وجود journal_entry_id). المُرحّل غير قابل للحذف — التصحيح بالعكس.
 * دورة الحالة محروسة: draft → approved → posted (+ rejected/cancelled قبل الترحيل، reversed بعده).
 */
class VoucherService
{
    /** بادئات ترقيم السندات حسب النوع. */
    private const PREFIX = [
        'receipt' => 'RV', 'payment' => 'PV', 'expense' => 'EXP', 'income' => 'INC', 'transfer' => 'TRF',
    ];

    public function __construct(private readonly AccountingService $accounting) {}

    /** @param array<string, mixed> $data */
    public function create(string $kind, array $data): FinancialVoucher
    {
        if (! in_array($kind, FinancialVoucher::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('نوع سند غير مدعوم.')]);
        }

        $date = $data['voucher_date'] ?? now()->toDateString();

        return FinancialVoucher::create(array_merge([
            'number' => NumberGenerator::next('financial_vouchers', 'number', self::PREFIX[$kind], (int) date('Y', strtotime($date))),
            'kind' => $kind,
            'status' => 'draft',
            'voucher_date' => $date,
            'currency' => 'ILS',
            'tax_amount' => 0,
            'created_by' => auth()->id(),
        ], collect($data)->only([
            'voucher_date', 'treasury_id', 'counter_treasury_id', 'counter_account_id', 'expense_category_id',
            'customer_id', 'supplier_id', 'employee_id', 'party_name', 'amount', 'currency',
            'payment_method', 'reference', 'category', 'tax_amount', 'description', 'notes',
            'attachments', 'is_recurring', 'recurrence',
        ])->all()));
    }

    /** @param array<string, mixed> $data */
    public function update(FinancialVoucher $voucher, array $data): FinancialVoucher
    {
        // المسودّة/المعتمد لم يُرحَّل بعد (لا قيد) فيُعدَّل مباشرةً. المُرحّل يُعدَّل عبر repost.
        if (! in_array($voucher->status, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => __('السند المُرحّل يُعدَّل بإعادة الترحيل (عكس + قيد مُصحّح).')]);
        }
        $voucher->update(collect($data)->only([
            'voucher_date', 'treasury_id', 'counter_treasury_id', 'counter_account_id', 'expense_category_id',
            'customer_id', 'supplier_id', 'employee_id', 'party_name', 'amount', 'currency',
            'payment_method', 'reference', 'category', 'tax_amount', 'description', 'notes',
            'attachments', 'is_recurring', 'recurrence',
        ])->all());

        VoucherRevised::dispatch($voucher);

        return $voucher;
    }

    /**
     * تعديل سند مُرحّل بطريقة محاسبية سليمة (ADR-016): يعكس القيد الأصلي بقيد عاكس، ثم يطبّق
     * القيم الجديدة ويُرحّل قيدًا مُصحّحًا. الأثر الصافي = القيم الجديدة فقط، دون تعديل قيد مُرحّل.
     *
     * @param  array<string, mixed>  $data
     */
    public function repost(FinancialVoucher $voucher, array $data, ?User $actor = null): FinancialVoucher
    {
        if ($voucher->status !== 'posted') {
            throw ValidationException::withMessages(['status' => __('إعادة الترحيل للسند المُرحّل فقط.')]);
        }

        return DB::transaction(function () use ($voucher, $data, $actor) {
            // 1) عكس القيد الأصلي (تصحيح لا حذف).
            if ($voucher->journalEntry && ! $voucher->journalEntry->isReversed()) {
                $this->accounting->reverse($voucher->journalEntry, [
                    'description' => __('عكس تعديل سند :n', ['n' => $voucher->number]),
                ]);
            }

            // 2) تطبيق القيم الجديدة.
            $voucher->update(collect($data)->only([
                'voucher_date', 'treasury_id', 'counter_treasury_id', 'counter_account_id', 'expense_category_id',
                'customer_id', 'supplier_id', 'employee_id', 'party_name', 'amount', 'currency',
                'payment_method', 'reference', 'category', 'tax_amount', 'description', 'notes', 'attachments',
            ])->all());

            // 3) ترحيل قيد مُصحّح بالقيم الجديدة وربطه بالسند.
            $voucher->load(['treasury.glAccount', 'counterAccount', 'counterTreasury.glAccount']);
            $entry = $this->accounting->postEntry([
                'entry_date' => $voucher->voucher_date->toDateString(),
                'description' => $voucher->description ?: $this->defaultDescription($voucher),
                'source' => 'voucher',
                'reference_type' => 'financial_voucher',
                'reference_id' => $voucher->id,
            ], $this->buildLines($voucher));

            $voucher->update([
                'journal_entry_id' => $entry->id,
                'posted_by' => $actor?->id ?? auth()->id(),
                'posted_at' => now(),
            ]);

            /*
            | **الوثائق المرتبطة تُصلح نفسها.**
            |
            | القيد صُحّح أعلاه، لكنّ وثيقةً في وحدةٍ أخرى قد تحمل نسخةً من مبلغ
            | السند — دفعةُ عمولة، تصفيةُ نهاية خدمة. وتركُها على القيمة القديمة
            | يجعل الدفتر يقول رقمًا والأرشيف رقمًا آخر، والفرقُ لا يظهر خطأً بل
            | رصيدًا كاذبًا يُصرف عليه.
            |
            | داخل المعاملة: إمّا أن يُصحَّح الكلّ أو لا شيء.
            */
            VoucherRevised::dispatch($voucher);

            return $voucher;
        });
    }

    public function approve(FinancialVoucher $voucher, ?User $actor = null): FinancialVoucher
    {
        $this->transition($voucher, 'approved');
        $voucher->update(['approved_by' => $actor?->id ?? auth()->id(), 'approved_at' => now()]);

        return $voucher;
    }

    public function reject(FinancialVoucher $voucher): FinancialVoucher
    {
        $this->transition($voucher, 'rejected');

        return $voucher;
    }

    public function cancel(FinancialVoucher $voucher): FinancialVoucher
    {
        $this->transition($voucher, 'cancelled');

        return $voucher;
    }

    /**
     * ترحيل السند: يُنشئ قيدًا متوازنًا عبر AccountingService. idempotent (لا تكرار).
     * يجب أن يكون السند «approved». يحترم الفترات المقفلة (يُفرَض داخل المحرّك).
     */
    public function post(FinancialVoucher $voucher, ?User $actor = null): FinancialVoucher
    {
        // idempotency: مُرحّل مسبقًا ⇒ لا تكرار.
        if ($voucher->journal_entry_id) {
            return $voucher;
        }
        if ($voucher->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('يُرحّل السند المعتمد فقط.')]);
        }

        return DB::transaction(function () use ($voucher, $actor) {
            $lines = $this->buildLines($voucher);

            $entry = $this->accounting->postEntry([
                'entry_date' => $voucher->voucher_date->toDateString(),
                'description' => $voucher->description ?: $this->defaultDescription($voucher),
                'source' => 'voucher',
                'reference_type' => 'financial_voucher',
                'reference_id' => $voucher->id,
            ], $lines);

            $voucher->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'idempotency_key' => 'voucher:'.$voucher->id,
                'posted_by' => $actor?->id ?? auth()->id(),
                'posted_at' => now(),
            ]);

            return $voucher;
        });
    }

    /** عكس سند مُرحّل بقيد عاكس (لا حذف). */
    public function reverse(FinancialVoucher $voucher, ?string $reason = null): FinancialVoucher
    {
        if (! $voucher->isPosted() || ! $voucher->journalEntry) {
            throw ValidationException::withMessages(['status' => __('لا يمكن عكس سند غير مُرحّل.')]);
        }

        return DB::transaction(function () use ($voucher, $reason) {
            $reversal = $this->accounting->reverse($voucher->journalEntry, [
                'description' => $reason ?: __('عكس سند :number', ['number' => $voucher->number]),
            ]);

            $voucher->update(['status' => 'reversed', 'reversal_entry_id' => $reversal->id]);

            return $voucher;
        });
    }

    /**
     * بناء سطري القيد حسب نوع السند (متوازن دائمًا).
     *
     * @return array<int, array{account_code:string, debit:float, credit:float}>
     */
    private function buildLines(FinancialVoucher $voucher): array
    {
        $amount = round((float) $voucher->amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('المبلغ يجب أن يكون موجبًا.')]);
        }

        $treasuryCode = fn ($t) => $t?->glAccount?->code
            ?? throw ValidationException::withMessages(['treasury' => __('الخزينة بلا حساب GL.')]);

        return match ($voucher->kind) {
            // قبض: مدين الخزينة / دائن الحساب المقابل (إيراد/ذمم/…).
            'receipt' => [
                ['account_code' => $treasuryCode($voucher->treasury), 'debit' => $amount, 'credit' => 0],
                ['account_code' => $this->counterCode($voucher), 'debit' => 0, 'credit' => $amount],
            ],
            // صرف: مدين الحساب المقابل (مصروف/ذمم/…) / دائن الخزينة.
            'payment' => [
                ['account_code' => $this->counterCode($voucher), 'debit' => $amount, 'credit' => 0],
                ['account_code' => $treasuryCode($voucher->treasury), 'debit' => 0, 'credit' => $amount],
            ],
            // مصروف: مدين حساب المصروف / دائن الخزينة.
            'expense' => [
                ['account_code' => $this->counterCode($voucher), 'debit' => $amount, 'credit' => 0],
                ['account_code' => $treasuryCode($voucher->treasury), 'debit' => 0, 'credit' => $amount],
            ],
            // إيراد آخر: مدين الخزينة / دائن حساب الإيراد.
            'income' => [
                ['account_code' => $treasuryCode($voucher->treasury), 'debit' => $amount, 'credit' => 0],
                ['account_code' => $this->counterCode($voucher), 'debit' => 0, 'credit' => $amount],
            ],
            // تحويل: مدين خزينة الوجهة / دائن خزينة المصدر.
            'transfer' => [
                ['account_code' => $treasuryCode($voucher->counterTreasury), 'debit' => $amount, 'credit' => 0],
                ['account_code' => $treasuryCode($voucher->treasury), 'debit' => 0, 'credit' => $amount],
            ],
            default => throw ValidationException::withMessages(['kind' => __('نوع سند غير مدعوم.')]),
        };
    }

    private function counterCode(FinancialVoucher $voucher): string
    {
        $account = $voucher->counterAccount;
        if (! $account || ! $account->is_postable) {
            throw ValidationException::withMessages(['counter_account_id' => __('اختر حساب مقابل صالح للترحيل.')]);
        }

        return $account->code;
    }

    private function transition(FinancialVoucher $voucher, string $to): void
    {
        if (! FinancialVoucher::canTransition($voucher->status, $to)) {
            throw ValidationException::withMessages([
                'status' => __('انتقال غير مسموح: :from → :to', ['from' => $voucher->status, 'to' => $to]),
            ]);
        }
        $voucher->update(['status' => $to]);
    }

    private function defaultDescription(FinancialVoucher $voucher): string
    {
        return match ($voucher->kind) {
            'receipt' => __('سند قبض :n', ['n' => $voucher->number]),
            'payment' => __('سند صرف :n', ['n' => $voucher->number]),
            'expense' => __('مصروف :n', ['n' => $voucher->number]),
            'income' => __('إيراد آخر :n', ['n' => $voucher->number]),
            'transfer' => __('تحويل :n', ['n' => $voucher->number]),
            default => $voucher->number,
        };
    }
}
