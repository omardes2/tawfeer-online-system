<?php

namespace App\Modules\AiAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * استدعاء أداةٍ واحد — يُكتب ولا يُعدَّل.
 *
 * وهو ما يجعل المبدأ الأول قابلًا للإثبات: إن قال الوكيل سعرًا خاطئًا، هنا
 * يُعرَف أجاء من أداةٍ أم اخترعه.
 */
class AgentToolCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agent_run_id', 'tool_name', 'arguments', 'result', 'status', 'duration_ms'];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('سجلّ استدعاءات الأدوات لا يُعدَّل.'));
        static::deleting(fn () => throw new RuntimeException('سجلّ استدعاءات الأدوات لا يُحذف.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
