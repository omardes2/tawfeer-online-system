<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * حجم الصفحة المطلوب من العميل، مقيّدًا بحدّ أقصى آمن لتفادي إرهاق الذاكرة.
     */
    protected function perPage(int $default = 15, int $max = 100): int
    {
        $value = request()->integer('per_page', $default);

        return max(1, min($value, $max));
    }

    /**
     * ترتيب آمن عبر قائمة أعمدة مسموح بها فقط (تفادي حقن أسماء الأعمدة).
     *
     * @param  array<int, string>  $allowed
     */
    protected function applySorting(Builder $query, Request $request, array $allowed, string $default = 'id', string $defaultDir = 'desc'): void
    {
        $sort = (string) $request->query('sort', '');
        $dir = strtolower((string) $request->query('direction', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy($default, $defaultDir);
        }
    }
}
