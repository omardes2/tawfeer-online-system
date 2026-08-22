<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * محادثةٌ في الصندوق الموحّد.
 *
 * `ai_mode` ثلاثيٌّ لا ثنائي، والفرق ليس تجميلًا: `paused` أوقفه إنسانٌ مؤقتًا
 * فيعود بانتهاء السبب، و`handed_off` سُلّم لموظفة فلا يعود إلّا بقرارٍ صريح.
 * وخلطُهما يعيد الوكيل إلى محادثةٍ حُوّلت لغضب الزبون.
 */
class Conversation extends Model
{
    use HasUuid, SoftDeletes;

    public const AI_ACTIVE = 'active';

    public const AI_PAUSED = 'paused';

    public const AI_HANDED_OFF = 'handed_off';

    protected $fillable = [
        'channel_contact_id', 'status_id', 'assigned_user_id', 'ai_mode',
        'handoff_reason', 'handoff_at', 'last_message_at', 'order_id',
    ];

    protected $casts = [
        'handoff_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    /**
     * الافتراضي على النموذج لا على الجدول وحده.
     *
     * افتراضُ قاعدة البيانات لا يعرفه الكائن قبل إعادة قراءته، فكانت المحادثة
     * المُنشأة للتوّ تُقرأ بلا وضعٍ للوكيل. والاتجاه هنا آمن (صمتٌ لا ثرثرة)،
     * لكنه صمتٌ بسبب خطأ لا بسبب قرار — والفرق يظهر يوم يُسأل: لماذا لم يردّ؟
     */
    protected $attributes = ['ai_mode' => self::AI_ACTIVE];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ChannelContact::class, 'channel_contact_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ConversationStatus::class, 'status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** يردّ الوكيل؟ حالة المحادثة ومفتاح القناة والمفتاح العام مجتمعةً. */
    public function agentMayReply(): bool
    {
        return $this->ai_mode === self::AI_ACTIVE
            && ($this->contact?->channel?->agentAnswers() ?? false);
    }

    public function scopeHandedOff(Builder $query): Builder
    {
        return $query->where('ai_mode', self::AI_HANDED_OFF);
    }
}
