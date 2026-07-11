<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * حجم الصفحة المطلوب من العميل، مقيّدًا بحدّ أقصى آمن لتفادي إرهاق الذاكرة.
     */
    protected function perPage(int $default = 15, int $max = 100): int
    {
        $value = request()->integer('per_page', $default);

        return max(1, min($value, $max));
    }
}
