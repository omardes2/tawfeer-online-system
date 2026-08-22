<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** حالة محادثةٍ قابلة للإدارة (المبدأ 10) — بنفس بنية حالات الطلب. */
class ConversationStatus extends Model
{
    protected $fillable = ['key', 'name', 'color', 'sort_order', 'is_default', 'is_final', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_final' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function defaultId(): ?int
    {
        return static::query()->where('is_default', true)->value('id')
            ?? static::query()->orderBy('sort_order')->value('id');
    }
}
