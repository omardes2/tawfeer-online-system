<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * سطر قيد (ADR-029، BR-ACC-09/10). append-only: مصدر الحقيقة للأرصدة؛ لا تعديل/حذف (المتطلّب 2/8).
 */
class JournalLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['journal_entry_id', 'account_id', 'line_no', 'debit', 'credit', 'currency', 'description', 'created_at'];

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        // append-only: يُمنع تعديل أو حذف سطر قيد عبر Eloquent (BR-ACC-09).
        static::updating(fn () => throw new RuntimeException('Journal lines are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Journal lines are immutable.'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
