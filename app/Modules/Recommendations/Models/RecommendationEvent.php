<?php

namespace App\Modules\Recommendations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إشارة أداء توصية (Phase 6 / ADR-045). append-only.
 */
class RecommendationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source_product_id', 'recommended_product_id', 'type', 'event', 'source', 'placement',
        'user_id', 'session_token', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
