<?php

namespace App\Modules\Returns\Support;

/**
 * مفردات وسير عمل المرتجعات/الاستبدال — RMA (Phase 4.4 / ADR-040، ADR-011، BR-RET-*).
 *
 * التدفّق القانوني: return_request → approved → received → inspected → completed
 * (+ rejected / cancelled). مسار الموافقة: مبيعات → مشرف → مستودع → قرار نهائي.
 */
final class ReturnWorkflow
{
    // الحالات القانونية.
    public const CREATED = 'return_request';

    public const APPROVED = 'approved';

    public const RECEIVED = 'received';

    public const INSPECTED = 'inspected';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** الأنواع. */
    public const TYPES = ['return', 'exchange', 'replacement'];

    /** الأسباب المُصنّفة (المتطلّبات). */
    public const REASONS = [
        'wrong_item',                // صنف خاطئ
        'damaged',                   // تالف
        'missing_item',              // ناقص/مفقود
        'customer_refused',          // رفض العميل الاستلام
        'delivery_company_issue',    // مشكلة شركة التوصيل
        'internal_warehouse_mistake', // خطأ مستودع داخلي
        'changed_mind',              // عدل العميل رأيه
        'other',                     // أخرى
    ];

    /** طرق التسوية المالية. */
    public const RESOLUTIONS = ['refund', 'no_refund', 'store_credit', 'replacement'];

    /** نتائج الفحص. */
    public const INSPECTION_RESULTS = ['sellable', 'damaged', 'wrong_item', 'missing_parts', 'quarantine'];

    /** توجيه المخزون بعد الفحص. */
    public const INVENTORY_ROUTES = ['restock', 'damaged', 'none'];

    /**
     * الانتقالات القانونية.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::CREATED => [self::APPROVED, self::REJECTED, self::CANCELLED],
        self::APPROVED => [self::RECEIVED, self::REJECTED, self::CANCELLED],
        self::RECEIVED => [self::INSPECTED, self::CANCELLED],
        self::INSPECTED => [self::COMPLETED, self::REJECTED],
        self::COMPLETED => [],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** المرحلة المسؤولة عن كل حالة مستهدفة (لسجلّ الموافقات). */
    public const STAGE = [
        self::APPROVED => 'supervisor',
        self::REJECTED => 'supervisor',
        self::RECEIVED => 'warehouse',
        self::INSPECTED => 'warehouse',
        self::COMPLETED => 'final',
        self::CANCELLED => 'sales',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function stageFor(string $to): ?string
    {
        return self::STAGE[$to] ?? null;
    }
}
