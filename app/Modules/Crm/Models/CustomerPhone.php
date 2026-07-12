<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** رقم هاتف عميل (BR-CUST-05). أساسي واحد كحدّ أقصى (خدمة). */
class CustomerPhone extends Model
{
    protected $fillable = ['customer_id', 'phone', 'label', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
