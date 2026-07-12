<?php

namespace App\Modules\Shipping\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * استثناء توصيل (ADR-039): SLA + تصعيد + مسؤول + إعادة فتح/حلّ. auditable + uuid.
 */
class DeliveryException extends Model
{
    use Auditable, HasUuid;

    protected $fillable = [
        'shipment_id', 'category_id', 'status', 'assigned_to',
        'sla_due_at', 'escalated_at', 'resolved_at', 'resolution',
        'reopened_count', 'opened_by',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reopened_count' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DeliveryExceptionCategory::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DeliveryExceptionNote::class)->orderBy('id');
    }

    /** استثناء مُتجاوِز مهلة SLA وغير محلول. */
    public function scopeBreached($query)
    {
        return $query->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())
            ->whereNotIn('status', ['resolved']);
    }
}
