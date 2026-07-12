<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قالب رسالة تسويق (Phase 6 / ADR-046) — عربي/إنجليزي.
 */
class CampaignTemplate extends Model
{
    protected $fillable = ['name', 'channel', 'subject', 'body_ar', 'body_en', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];
}
