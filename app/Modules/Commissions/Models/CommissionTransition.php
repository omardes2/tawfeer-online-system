<?php

namespace App\Modules\Commissions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تحوّل حالة عمولة (Phase 4.2) — append-only (لا updated_at).
 */
class CommissionTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['commission_entry_id', 'from_state', 'to_state', 'actor_id', 'reference', 'note'];
}
