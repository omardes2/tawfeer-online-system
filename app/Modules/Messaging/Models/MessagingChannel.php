<?php

namespace App\Modules\Messaging\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قناة مراسلةٍ مربوطة (واتساب الآن، وماسنجر وإنستغرام لاحقًا خلف العقد نفسه).
 *
 * `ai_enabled` مفتاح إطفاءٍ لكل قناة: إيقاف الوكيل قرارٌ إداريّ يُنفَّذ من اللوحة
 * في ثانية، لا بنشر كودٍ ولا بتعديل `.env`.
 */
class MessagingChannel extends Model
{
    use Auditable, HasUuid;

    protected $fillable = [
        'provider', 'name', 'external_id', 'waba_id', 'credentials', 'is_active', 'ai_enabled',
    ];

    protected $casts = [
        // مشفّرة في العمود: القناة تُدار من اللوحة فلا مكان لأسرارها في `.env`،
        // والتشفير يمنع قراءتها من نسخةٍ احتياطية مسرّبة.
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'ai_enabled' => 'boolean',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(ChannelContact::class, 'channel_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** هل يردّ الوكيل على هذه القناة؟ القناة والمفتاح العام معًا. */
    public function agentAnswers(): bool
    {
        return $this->is_active && $this->ai_enabled && (bool) config('ai_agent.enabled', false);
    }
}
