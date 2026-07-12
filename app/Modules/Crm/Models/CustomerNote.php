<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملاحظة عميل (سجلّ زمني — append-only بلا updated_at). */
class CustomerNote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'body', 'created_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
