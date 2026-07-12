<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\HasUuid;
use Database\Factories\Foundation\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طريقة دفع (سجلّ المزوّدين — ADR-028). يُوصَل المزوّد حصريًا عبر طبقة التكامل (المبدأ 13).
 */
class PaymentMethod extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = ['name', 'code', 'driver', 'type', 'is_active', 'config', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'config' => 'array', 'sort_order' => 'integer'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isOffline(): bool
    {
        return $this->type === 'offline';
    }

    protected static function newFactory(): Factory
    {
        return PaymentMethodFactory::new();
    }
}
