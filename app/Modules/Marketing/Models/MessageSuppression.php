<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حجب صريح لرسائل التسويق — opt-out (Phase 6 / ADR-046). append-only.
 */
class MessageSuppression extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'contact', 'channel', 'reason', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
