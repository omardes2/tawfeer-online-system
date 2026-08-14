<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Catalog\ProductReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * تقييم زبون لمنتج.
 *
 * يبدأ `pending` ولا يظهر في المتجر حتى يعتمده مخوَّل — محتوى عام يكتبه طرف
 * خارجي، ونشره بلا مراجعة يفتح باب السبام والإساءة على متجر مباشر.
 */
class ProductReview extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'product_id', 'customer_id', 'order_id',
        'rating', 'title', 'body',
        'status', 'moderated_by', 'moderated_at', 'moderation_note',
    ];

    protected $casts = [
        'rating' => 'integer',
        'moderated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** كل تقييم مربوط بطلب مستلَم، فالشارة تُعرض لمن له `order_id`. */
    public function isVerifiedPurchase(): bool
    {
        return $this->order_id !== null;
    }

    protected static function newFactory(): Factory
    {
        return ProductReviewFactory::new();
    }
}
