<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * هويّة اجتماعية مربوطة بمستخدم (Phase 3.5 / ADR-036). معرّفات المزوّد فقط —
 * لا أسرار. (provider, provider_id) فريد لمنع تكرار الحسابات.
 */
class SocialIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_id', 'email', 'name', 'avatar'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
