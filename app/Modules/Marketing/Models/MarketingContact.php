<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهة اتصال تسويقية — رقمٌ في قائمة، لا عميلٌ ذو ذمّة.
 */
class MarketingContact extends Model
{
    public const CONSENT_UNKNOWN = 'unknown';

    public const CONSENT_IMPLIED = 'implied';

    public const CONSENT_EXPLICIT = 'explicit';

    public const CONSENT_OPTED_OUT = 'opted_out';

    /** @var array<string, string> */
    public const CONSENT_LABELS = [
        self::CONSENT_UNKNOWN => 'غير معروفة',
        self::CONSENT_IMPLIED => 'ضمنية (اشترى أو راسل)',
        self::CONSENT_EXPLICIT => 'صريحة',
        self::CONSENT_OPTED_OUT => 'انسحب',
    ];

    protected $fillable = [
        'phone', 'phone_raw', 'name', 'customer_id',
        'source', 'source_ref',
        'consent_state', 'consent_basis', 'consent_at',
        'last_contacted_at', 'blocked_at', 'extra', 'imported_by',
    ];

    protected $casts = [
        'consent_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'blocked_at' => 'datetime',
        'extra' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * من يجوز مراسلته.
     *
     * ثلاثة شروط لا اثنان: أساسُ موافقةٍ ما، وألّا يكون انسحب، و**ألّا يكون
     * حجبنا سابقًا**. والثالث هو الذي يحمي الرقم: مراسلةُ من حجبك تُبلَّغ
     * فورًا، وتراكمُها يُسقط درجة الجودة ثم يُحظر الرقم.
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query
            ->whereIn('consent_state', [self::CONSENT_IMPLIED, self::CONSENT_EXPLICIT])
            ->whereNull('blocked_at');
    }

    public function consentLabel(): string
    {
        return __(self::CONSENT_LABELS[$this->consent_state] ?? $this->consent_state);
    }

    public function isSendable(): bool
    {
        return $this->blocked_at === null
            && in_array($this->consent_state, [self::CONSENT_IMPLIED, self::CONSENT_EXPLICIT], true);
    }
}
