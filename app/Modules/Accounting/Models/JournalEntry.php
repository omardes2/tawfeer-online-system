<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Accounting\JournalEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * قيد يومية (ADR-029/016). المُرحّل (posted) غير قابل للتعديل/الحذف نهائيًا (BR-ACC-08/09).
 * التصحيح بقيد عاكس فقط. draft → posted.
 */
class JournalEntry extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * سماح محكوم بحذف قيد مُرحّل ضمن عملية إدارية واحدة (حذف الفاتورة المرتبطة يحذف قيدها
     * تلقائيًا — قرار إداري). يُفعَّل حصرًا داخل AccountingService::deleteEntry ثم يُعاد لـfalse،
     * فلا يُضعِف الحماية في أي مسار آخر (التعديل/الحذف العابر لقيد مُرحّل يبقى ممنوعًا).
     */
    public static bool $allowPostedDeletion = false;

    protected $fillable = [
        'number', 'fiscal_year_id', 'period_id', 'entry_date', 'description',
        'source', 'status', 'reference_type', 'reference_id', 'reverses_entry_id',
        'posted_at', 'created_by', 'posted_by',
    ];

    protected $casts = ['entry_date' => 'date', 'posted_at' => 'datetime'];

    protected static function booted(): void
    {
        // حماية عدم القابلية للتعديل: لا تعديل لقيد مُرحّل (المتطلّب 8، BR-ACC-09).
        static::updating(function (self $entry) {
            if ($entry->getOriginal('status') === 'posted') {
                throw new RuntimeException('Posted journal entries are immutable.');
            }
        });
        // الحذف ممنوع لقيد مُرحّل إلا ضمن عملية إدارية محكومة (حذف الفاتورة المرتبطة).
        static::deleting(function (self $entry) {
            if (! self::$allowPostedDeletion
                && ($entry->getOriginal('status') === 'posted' || $entry->status === 'posted')) {
                throw new RuntimeException('Posted journal entries cannot be deleted.');
            }
        });
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    /** "معكوس" تُشتقّ من وجود قيد عاكس مُرحّل (لا نعدّل الأصل — المتطلّب 8). */
    public function isReversed(): bool
    {
        // يستخدم العلاقة المُحمّلة إن وُجدت (تفادي N+1 في القوائم).
        if ($this->relationLoaded('reversal')) {
            return $this->reversal->contains(fn ($e) => $e->status === 'posted');
        }

        return $this->reversal()->where('status', 'posted')->exists();
    }

    protected static function newFactory(): Factory
    {
        return JournalEntryFactory::new();
    }
}
