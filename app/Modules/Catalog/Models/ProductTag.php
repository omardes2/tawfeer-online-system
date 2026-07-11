<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductTagFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * وسم المنتج — تسمية تسويقية حرّة (PHASE_2_DESIGN §21). مرجعي: لا uuid/soft-delete.
 */
class ProductTag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return ProductTagFactory::new();
    }
}
