<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * مسيّر رواتب شهر.
 *
 * دورة الحالة: `draft` → `posted` → `paid`، و`reversed` بعد الترحيل.
 * المسودّة وحدها تُعاد توليدًا وتُحذف؛ والمُرحَّل مستندٌ يُصحَّح بالعكس لا
 * بالتعديل.
 */
class PayrollRun extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    public const STATUS_LABELS = [
        'draft' => 'مسودّة',
        'posted' => 'مُرحَّل',
        'paid' => 'مدفوع',
        'reversed' => 'معكوس',
    ];

    protected $fillable = [
        'number', 'period_year', 'period_month', 'status',
        'total_earnings', 'total_deductions', 'total_net', 'total_eos',
        'journal_entry_id', 'eos_journal_entry_id', 'notes',
        'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'total_earnings' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_eos' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<PayrollLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function eosJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'eos_journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return in_array($this->status, ['posted', 'paid'], true);
    }

    /** آخر يومٍ في الشهر — تاريخُ استحقاق الراتب وتاريخُ قيده. */
    public function periodEnd(): Carbon
    {
        return Carbon::create($this->period_year, $this->period_month, 1)->endOfMonth();
    }

    public function periodStart(): Carbon
    {
        return Carbon::create($this->period_year, $this->period_month, 1)->startOfDay();
    }

    public function periodLabel(): string
    {
        return str_pad((string) $this->period_month, 2, '0', STR_PAD_LEFT).'/'.$this->period_year;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** ما لم يُدفع بعدُ من بنود المسيّر. */
    public function unpaidTotal(): float
    {
        return round((float) $this->lines()->whereNull('financial_voucher_id')->sum('net'), 2);
    }
}
