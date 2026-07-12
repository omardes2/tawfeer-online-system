<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جهة اتصال عميل. جهة أساسية واحدة كحدّ أقصى (خدمة). */
class CustomerContact extends Model
{
    protected $fillable = ['customer_id', 'name', 'position', 'email', 'phone', 'is_primary', 'notes'];

    protected $casts = ['is_primary' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
