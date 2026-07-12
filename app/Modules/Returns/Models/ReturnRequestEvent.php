<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حدث/انتقال حالة طلب مرتجع (Phase 4.4 / ADR-040). append-only — الخطّ الزمني + سجلّ الحالة.
 */
class ReturnRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['return_request_id', 'from_status', 'to_status', 'stage', 'actor_id', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
