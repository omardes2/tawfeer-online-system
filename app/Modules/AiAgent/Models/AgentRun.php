<?php

namespace App\Modules\AiAgent\Models;

use App\Modules\Messaging\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * استدعاءٌ واحد للنموذج — سجلٌّ **يُكتب ولا يُعدَّل**.
 *
 * والمنع في الكود لا في النيّة: `updated` و`deleting` يرفعان استثناءً، فمن
 * يحاول تعديل السجلّ يصطدم بالمنع لا بمراجعةٍ بشرية. وأوّل ما يُغرى بمحوه هو
 * الاستدعاء الذي أخطأ — وهو بالضبط ما يجب أن يبقى.
 */
class AgentRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id', 'trigger_message_ids', 'model',
        'input_tokens', 'cache_write_tokens', 'cache_read_tokens',
        'output_tokens', 'cost', 'latency_ms', 'outcome', 'error',
    ];

    protected $casts = [
        'trigger_message_ids' => 'array',
        'input_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost' => 'decimal:4',
        'latency_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('سجلّ استدعاءات الوكيل لا يُعدَّل.'));
        static::deleting(fn () => throw new RuntimeException('سجلّ استدعاءات الوكيل لا يُحذف.'));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }
}
