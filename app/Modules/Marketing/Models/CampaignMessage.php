<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسالة حملة (Phase 6 / ADR-046). سجلّ تنفيذ: حالة/محاولات/idempotency.
 */
class CampaignMessage extends Model
{
    protected $fillable = [
        'campaign_id', 'customer_id', 'marketing_contact_id', 'channel', 'recipient', 'body', 'status',
        'provider_reference', 'error', 'idempotency_key', 'attempts', 'is_test', 'sent_at',
    ];

    protected $casts = ['attempts' => 'integer', 'is_test' => 'boolean', 'sent_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
