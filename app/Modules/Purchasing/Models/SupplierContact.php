<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهة اتصال المورد (PHASE_2_DESIGN §10). جهة أساسية واحدة كحدّ أقصى (خدمة).
 */
class SupplierContact extends Model
{
    protected $fillable = ['supplier_id', 'name', 'position', 'email', 'phone', 'is_primary', 'notes'];

    protected $casts = ['is_primary' => 'boolean'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
