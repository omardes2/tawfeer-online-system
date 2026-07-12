<?php

namespace App\Modules\Shipping\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملاحظة على استثناء توصيل (ADR-039). append-only: بلا updated_at.
 */
class DeliveryExceptionNote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['delivery_exception_id', 'note', 'kind', 'author_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(DeliveryException::class, 'delivery_exception_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
