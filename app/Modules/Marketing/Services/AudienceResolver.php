<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * حلّال جمهور الحملات (Phase 6 / ADR-046).
 *
 * يبني قائمة العملاء المستهدفين من فلاتر الحملة (audience json) — للحملات المجدولة/اليدوية.
 * الحملات المُحفَّزة بحدث يحدَّد عميلها من سياق الحدث مباشرةً (لا يمرّ من هنا). يستبعد دائمًا
 * المحظورين والمدموجين. لا يخترع بيانات عميل جديدة — يقرأ الحقول المعتمدة فقط.
 */
class AudienceResolver
{
    /** @return Collection<int, Customer> */
    public function resolve(Campaign $campaign, int $limit = 5000): Collection
    {
        $filters = (array) ($campaign->audience ?? []);

        $query = Customer::query()
            ->where('is_blocked', false)
            ->whereNull('merged_into_id');

        // معرّفات صريحة تتجاوز بقيّة الفلاتر.
        if (! empty($filters['customer_ids'])) {
            return $query->whereIn('id', (array) $filters['customer_ids'])->limit($limit)->get();
        }

        $this->applyFilters($query, $filters);

        return $query->limit($limit)->get();
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        // أعياد الميلاد اليوم (من birth_date الموجود — بلا بيانات جديدة).
        if (! empty($filters['birthday_today'])) {
            $query->whereNotNull('birth_date')
                ->whereMonth('birth_date', now()->month)
                ->whereDay('birth_date', now()->day);
        }

        // خمول: لا طلبات منذ N يومًا.
        if (! empty($filters['inactive_days'])) {
            $days = (int) $filters['inactive_days'];
            $query->whereDoesntHave('orders', fn ($q) => $q->where('created_at', '>=', now()->subDays($days)));
        }

        // فئة العميل.
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // وسم ضمن tags (json).
        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        // مصدر الاكتساب.
        if (! empty($filters['acquisition_source'])) {
            $query->where('acquisition_source', $filters['acquisition_source']);
        }
    }
}
