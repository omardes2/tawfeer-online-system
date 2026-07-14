<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إعداد ترحيل محاسبي: نوع المستند + وظيفة → حساب من دليل الحسابات.
 */
class AccountMapping extends Model
{
    protected $fillable = ['document_type', 'function', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
