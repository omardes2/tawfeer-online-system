<?php

namespace App\Modules\Ai\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ استخدام مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044). append-only.
 */
class AiGenerationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'product_id', 'type', 'action', 'locale', 'provider', 'model',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'status', 'error', 'created_at',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
