<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة دليل مرتجع (Phase 4.4 / ADR-040). append-only.
 */
class ReturnRequestPhoto extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['return_request_id', 'path', 'uploaded_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
