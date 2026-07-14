<?php

namespace App\Modules\Shipping\Support;

/**
 * المفردات القانونية لدورة حياة التوصيل (Canonical Delivery Lifecycle — ADR-038).
 *
 * هذه الحالات هي **المصدر الداخلي القانوني** لسير عمل التوصيل الرسمي (مصدره تدفّق Opost)،
 * ومنفصلة تمامًا عن حالات المزوّد الخام (provider_status). لا يجوز لأي وحدة أعمال قراءة
 * حالة مزوّد؛ تقرأ فقط هذه الحالات القانونية. تعيين مزوّد ← قانوني يقع في طبقة التكامل
 * (Driver) لا هنا (المبدأ 13).
 *
 * ملاحظة مهمّة: أسماء Opost مضلّلة (`cod_pickup` = سُلّم للعميل والنقد محصّل لدى المندوب،
 * و`delivered` = بضاعة مرتجعة عائدة إلينا)، لذا نستخدم أسماء قانونية واضحة.
 */
final class DeliveryStatus
{
    public const DRAFT = 'draft';                                   // مسودّة قابلة للتعديل (Opost: draft)

    public const READY_FOR_PICKUP = 'ready_for_pickup';            // مُسلّمة لشركة التوصيل (Opost: submit)

    public const CANCELLED = 'cancelled';                          // مُلغاة قبل الاستلام (Opost: cancel) — نهائية

    public const PICKED_UP = 'picked_up';                          // استلمها المندوب (Opost: pickup)

    public const ON_HOLD = 'on_hold';                             // معلّقة لمشكلة توصيل (Opost: pending)

    public const DELIVERED_COD_HELD = 'delivered_cod_held';       // سُلّمت للعميل، النقد لدى المندوب (Opost: cod_pickup)

    public const FUNDS_AT_ACCOUNTING = 'funds_at_courier_accounting'; // وصل المبلغ لمحاسبة المندوب (Opost: in_accounting)

    public const RETURNING_TO_COURIER = 'returning_to_courier';   // مرتجعة لمستودع المندوب (Opost: return)

    public const RETURN_IN_TRANSIT = 'return_in_transit';         // بضاعة مرتجعة عائدة إلينا (Opost: delivered)

    public const CLOSED = 'closed';                               // إغلاق مالي نهائي (Opost: close) — نهائية

    /** الحالة الابتدائية عند إنشاء الشحنة. */
    public const INITIAL = self::DRAFT;

    /**
     * الانتقالات القانونية للحالة الداخلية (رسم بياني، ليست خطّية).
     * مساران يلتقيان عند CLOSED: مسار التحصيل ومسار الإرجاع.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::READY_FOR_PICKUP, self::CANCELLED],
        self::READY_FOR_PICKUP => [self::PICKED_UP, self::CANCELLED],
        self::PICKED_UP => [self::ON_HOLD, self::DELIVERED_COD_HELD, self::RETURNING_TO_COURIER],
        self::ON_HOLD => [self::PICKED_UP, self::DELIVERED_COD_HELD, self::RETURNING_TO_COURIER],
        self::DELIVERED_COD_HELD => [self::FUNDS_AT_ACCOUNTING],
        self::FUNDS_AT_ACCOUNTING => [self::CLOSED],
        self::RETURNING_TO_COURIER => [self::RETURN_IN_TRANSIT],
        self::RETURN_IN_TRANSIT => [self::CLOSED],
        self::CLOSED => [],
        self::CANCELLED => [],
    ];

    /**
     * أسباب التعليق المُصنّفة (المتطلّب 2 — قابلة للتقرير). تُلزَم عند الانتقال إلى on_hold يدويًا.
     *
     * @var list<string>
     */
    public const HOLD_REASONS = [
        'customer_no_answer',        // العميل لا يردّ
        'wrong_phone',               // رقم هاتف خاطئ
        'wrong_address',             // عنوان خاطئ
        'customer_requested_delay',  // العميل طلب تأجيلًا
        'customer_refused',          // العميل رفض الاستلام
        'area_unavailable',          // المنطقة غير مغطّاة
        'courier_issue',             // مشكلة لدى المندوب/الشركة
        'business_issue',            // مشكلة لدى المتجر
        'other',                     // أخرى (نصّ حرّ في note)
    ];

    /** الحالات النهائية. */
    public const TERMINAL = [self::CLOSED, self::CANCELLED];

    /** تسميات عربية لعرض حالة التوصيل في الواجهة. */
    public const LABELS = [
        self::DRAFT => 'مسودّة',
        self::READY_FOR_PICKUP => 'جاهزة للاستلام',
        self::PICKED_UP => 'استلمها المندوب',
        self::ON_HOLD => 'معلّقة',
        self::DELIVERED_COD_HELD => 'سُلّمت (تحصيل)',
        self::FUNDS_AT_ACCOUNTING => 'في محاسبة المندوب',
        self::RETURNING_TO_COURIER => 'مرتجعة للمندوب',
        self::RETURN_IN_TRANSIT => 'مرتجع عائد',
        self::CLOSED => 'مُغلقة',
        self::CANCELLED => 'ملغاة',
    ];

    /** التسمية العربية لحالة التوصيل (أو المفتاح نفسه إن لم تُعرّف). */
    public static function label(?string $status): string
    {
        return $status === null ? '—' : (self::LABELS[$status] ?? $status);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isValidReason(string $reason): bool
    {
        return in_array($reason, self::HOLD_REASONS, true);
    }
}
