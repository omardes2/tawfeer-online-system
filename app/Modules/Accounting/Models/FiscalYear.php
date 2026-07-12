<?php

namespace App\Modules\Accounting\Models;

use App\Support\Concerns\HasUuid;
use Database\Factories\Accounting\FiscalYearFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سنة مالية (ADR-029، المتطلّب 7). تدعم تعدد السنوات دون إعادة تصميم.
 */
class FiscalYear extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['name', 'start_date', 'end_date', 'status'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    protected static function newFactory(): Factory
    {
        return FiscalYearFactory::new();
    }
}
