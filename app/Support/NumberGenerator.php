<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * توليد أرقام أعمال متسلسلة آمنة `PREFIX-{YYYY}-{seq}` (ADR-002).
 * يُستدعى داخل معاملة؛ يقفل آخر رقم للنوع×السنة لتفادي التكرار.
 */
class NumberGenerator
{
    /**
     * @param  string  $table  الجدول الذي يحوي العمود
     * @param  string  $column  عمود الرقم (مثل number)
     * @param  string  $prefix  البادئة (مثل ADJ)
     * @param  int  $year  السنة (تُمرّر من الخارج لتفادي دوال الوقت داخل منطق قابل للإعادة)
     */
    public static function next(string $table, string $column, string $prefix, int $year, int $pad = 6): string
    {
        $like = "{$prefix}-{$year}-%";

        $last = DB::table($table)
            ->where($column, 'like', $like)
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return sprintf('%s-%d-%0'.$pad.'d', $prefix, $year, $seq);
    }
}
