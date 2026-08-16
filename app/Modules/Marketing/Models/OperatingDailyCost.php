<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * المصروف التشغيلي الثابت لليوم، بتاريخ سريان.
 *
 * السجلّ الساري ليومٍ ما هو أحدثُ سجلٍّ تاريخُ سريانه ≤ ذلك اليوم — فتغيّر
 * الرواتب اليوم لا يُعيد كتابة ربح الشهر الماضي.
 */
class OperatingDailyCost extends Model
{
    protected $fillable = ['effective_from', 'amount', 'note', 'created_by'];

    protected $casts = [
        'effective_from' => 'date',
        'amount' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** المصروف الساري في يومٍ بعينه — صفرٌ إن لم يُضبط بعد. */
    public static function amountFor(Carbon|string $day): float
    {
        $date = $day instanceof Carbon ? $day->toDateString() : $day;

        return (float) static::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->value('amount');
    }
}
