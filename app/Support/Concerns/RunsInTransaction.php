<?php

namespace App\Support\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * غلاف معاملات ذرّية (المبدأ 7 في ARCHITECTURE.md).
 *
 * تستخدمه خدمات الوحدات لضمان أن كل عملية مالية/مخزونية تتم بالكامل أو تُلغى
 * بالكامل. تُبنى الخدمات المستقبلية (Orders, Accounting, Inventory) فوقه.
 */
trait RunsInTransaction
{
    protected function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }
}
