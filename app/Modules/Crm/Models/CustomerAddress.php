<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عنوان عميل (BR-CUST-06، ADR-014). افتراضي واحد كحدّ أقصى (خدمة). */
class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id', 'label', 'recipient_name', 'phone',
        'governorate_id', 'city_id', 'area_id', 'address_line', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
