<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسالةٌ في محادثة، واردةً أو صادرة.
 *
 * `external_id` (وهو `wamid`) فريدٌ في قاعدة البيانات لا في الكود: ميتا تُعيد
 * الـwebhook عند أي تأخّر، والحارس البرمجيّ وحده يُنقَض بسباق تنفيذٍ متزامن —
 * فتُخزَّن الرسالة مرّتين ويردّ الوكيل مرّتين على سؤالٍ واحد.
 */
class Message extends Model
{
    public const IN = 'inbound';

    public const OUT = 'outbound';

    protected $fillable = [
        'conversation_id', 'external_id', 'direction', 'sender_type', 'sender_user_id',
        'type', 'body', 'media_path', 'payload', 'delivery_status', 'failed_reason', 'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
