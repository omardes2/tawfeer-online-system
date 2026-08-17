<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * منشور تواصل اجتماعي — مسوّدة أو جاهز أو منشور.
 */
class SocialPost extends Model
{
    public const PLATFORMS = [
        'facebook' => 'فيسبوك',
        'instagram' => 'إنستغرام',
        'both' => 'فيسبوك وإنستغرام',
    ];

    public const STATUSES = [
        'draft' => 'مسوّدة',
        'ready' => 'جاهز للنشر',
        'published' => 'نُشر',
    ];

    protected $fillable = [
        'product_id', 'ad_channel_id', 'platform', 'locale', 'tone',
        'body', 'hashtags', 'link', 'status', 'published_at',
        'ai_model', 'ai_status', 'created_by',
    ];

    protected $casts = ['published_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class, 'ad_channel_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function platformLabel(): string
    {
        return __(self::PLATFORMS[$this->platform] ?? $this->platform);
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status] ?? $this->status);
    }

    /** النصّ كما يُنسخ إلى المنصّة: المتن ثم الوسوم ثم الرابط. */
    public function fullText(): string
    {
        return trim(implode("\n\n", array_filter([
            trim($this->body),
            trim((string) $this->hashtags),
            $this->link,
        ])));
    }
}
