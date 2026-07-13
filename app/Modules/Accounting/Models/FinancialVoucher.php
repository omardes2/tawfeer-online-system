<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Purchasing\Models\Supplier;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سند مالي موحّد (Phase 7.1): قبض/صرف/مصروف/إيراد آخر/تحويل.
 * دورة الحالة: draft → approved → posted (+ rejected/cancelled قبل الترحيل، reversed بعده).
 * الترحيل يُنشئ قيدًا متوازنًا عبر AccountingService؛ المُرحّل غير قابل للحذف — التصحيح بالعكس.
 */
class FinancialVoucher extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    public const KINDS = ['receipt', 'payment', 'expense', 'income', 'transfer'];

    public const TRANSITIONS = [
        'draft' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['posted', 'cancelled', 'draft'],
        'posted' => ['reversed'],
        'rejected' => [],
        'cancelled' => [],
        'reversed' => [],
    ];

    protected $fillable = [
        'number', 'kind', 'status', 'voucher_date', 'treasury_id', 'counter_treasury_id', 'counter_account_id',
        'customer_id', 'supplier_id', 'employee_id', 'party_name', 'amount', 'currency', 'payment_method',
        'reference', 'category', 'tax_amount', 'description', 'notes', 'attachments', 'is_recurring', 'recurrence',
        'idempotency_key', 'journal_entry_id', 'reversal_entry_id',
        'created_by', 'approved_by', 'posted_by', 'approved_at', 'posted_at',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'attachments' => 'array',
        'recurrence' => 'array',
        'is_recurring' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function counterTreasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'counter_treasury_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
