<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * أساس للحالات القابلة للإدارة من لوحة التحكم (المبدأ 10 في ARCHITECTURE.md).
 *
 * حالات الطلب/الدفع/الشحن ليست enum مغلقًا في الكود، بل صفوف في قاعدة
 * البيانات يمكن إضافتها/تعديلها/تعطيلها دون تعديل الكود.
 */
abstract class ManageableStatus extends Model
{
    use Auditable;

    protected $fillable = [
        'key',
        'name',
        'color',
        'sort_order',
        'is_default',
        'is_final',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'is_final' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
