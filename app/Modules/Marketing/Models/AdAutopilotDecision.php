<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قرارُ طيّارٍ واحد على مجموعة إعلانية واحدة في يوم.
 */
class AdAutopilotDecision extends Model
{
    public const ACTION_PAUSE = 'pause';

    public const ACTION_RESUME = 'resume';

    public const ACTION_DECREASE = 'decrease';

    public const ACTION_INCREASE = 'increase';

    public const ACTION_SKIP = 'skip';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'decided_on', 'report_day', 'ad_channel_id', 'product_id',
        'external_id', 'external_name', 'action', 'verdict', 'reason',
        'budget_before', 'budget_after', 'currency',
        'window_spend', 'window_orders', 'window_cpa', 'window_net_profit',
        'status', 'error', 'source', 'mode',
        'applied_at', 'reverted_at', 'reverted_by', 'created_by',
    ];

    protected $casts = [
        'decided_on' => 'date',
        'report_day' => 'date',
        'budget_before' => 'decimal:2',
        'budget_after' => 'decimal:2',
        'window_spend' => 'decimal:2',
        'window_cpa' => 'decimal:2',
        'window_net_profit' => 'decimal:2',
        'window_orders' => 'integer',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    /** أفعالٌ تلمس المنصّة فعلًا — و«التخطّي» ليس منها. */
    public const EFFECTIVE_ACTIONS = [
        self::ACTION_PAUSE, self::ACTION_RESUME, self::ACTION_DECREASE, self::ACTION_INCREASE,
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class, 'ad_channel_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPLIED);
    }

    /**
     * هل يمكن التراجع عن هذا القرار؟
     *
     * المنفَّذُ وحده، وذو الفعل الذي له نقيض. و«التخطّي» لا يُتراجَع عنه لأنه
     * لم يفعل شيئًا.
     */
    public function isRevertible(): bool
    {
        return $this->status === self::STATUS_APPLIED
            && in_array($this->action, self::EFFECTIVE_ACTIONS, true);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_PAUSE => __('أوقف'),
            self::ACTION_RESUME => __('شغّل'),
            self::ACTION_DECREASE => __('خفّض الميزانية'),
            self::ACTION_INCREASE => __('رفع الميزانية'),
            default => __('لم يتدخّل'),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPLIED => __('نُفِّذ'),
            self::STATUS_SKIPPED => __('تُخطّي'),
            self::STATUS_FAILED => __('فشل'),
            self::STATUS_REVERTED => __('أُلغي'),
            default => __('مقترح'),
        };
    }
}
