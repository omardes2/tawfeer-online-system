<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * حملة أتمتة تسويق (Phase 6 / ADR-046). كيان حسّاس: uuid + soft-delete + auditable.
 */
class Campaign extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    public const TRANSITIONS = [
        'draft' => ['pending_approval', 'archived'],
        'pending_approval' => ['approved', 'draft'],
        'approved' => ['active', 'draft'],
        'active' => ['paused', 'completed'],
        'paused' => ['active', 'completed', 'archived'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    protected $fillable = [
        'name', 'use_case', 'channel', 'status', 'trigger_type', 'trigger_event', 'audience',
        'delay_minutes', 'scheduled_at', 'template_id', 'body_ar', 'body_en', 'subject',
        'frequency_cap_days', 'quiet_start', 'quiet_end', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'audience' => 'array',
        'scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
        'delay_minutes' => 'integer',
        'frequency_cap_days' => 'integer',
        'quiet_start' => 'integer',
        'quiet_end' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
