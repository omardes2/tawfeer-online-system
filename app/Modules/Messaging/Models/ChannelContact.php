<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * هوية المُراسِل على القناة.
 *
 * منفصلةٌ عن `customers` لأن المحادثة تسبق العميل: من يسأل عن منتجٍ ليس عميلًا
 * بعد وقد لا يصير، وربطُه من اللحظة الأولى يملأ قاعدة العملاء بمن لم يشترِ
 * فيُفسد كل عدٍّ وتقرير.
 */
class ChannelContact extends Model
{
    protected $fillable = [
        'channel_id', 'external_id', 'display_name', 'customer_id', 'last_inbound_at',
    ];

    protected $casts = ['last_inbound_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MessagingChannel::class, 'channel_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * هل نافذة الأربع والعشرين ساعة مفتوحة؟
     *
     * قاعدة ميتا: لا نصَّ حرًّا بعد أربعٍ وعشرين ساعة من آخر رسالةٍ واردة — قوالبُ
     * معتمَدة فقط. وتجاوزها لا يُنتج خطأً فحسب بل يخصم من تقييم الرقم.
     */
    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null
            && $this->last_inbound_at->greaterThan(now()->subDay());
    }
}
