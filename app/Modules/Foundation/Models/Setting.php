<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعداد ديناميكي مخزّن في قاعدة البيانات (المبدأ 9 في ARCHITECTURE.md).
 *
 * لا يُقرأ مباشرة عادةً — تُستخدم طبقة App\Modules\Foundation\Services\SettingsManager
 * (عبر الـ Facade `Settings`) للقراءة/الكتابة مع تخزين مؤقت.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
