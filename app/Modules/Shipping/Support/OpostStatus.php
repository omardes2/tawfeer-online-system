<?php

namespace App\Modules\Shipping\Support;

/**
 * تسميات عربية لحالات أوبتيموس الخام (كما ترد من المزوّد) — للعرض والفلترة في «حالة أوبتيموس».
 * ملاحظة: أسماء أوبتيموس مضلّلة — `delivered` تعني «مرتجع مع السائق»، و`returned` «مرتجع لدى الشركة».
 */
class OpostStatus
{
    /** @var array<string, string> */
    public const LABELS = [
        'draft' => 'مسودّة',
        'submit' => 'بانتظار الاستلام',
        'submitted' => 'بانتظار الاستلام',
        'pending' => 'معلّق',
        'pickup' => 'استلمها المندوب',
        'picked_up' => 'استلمها المندوب',
        'cod_pickup' => 'سُلّم (تحصيل نقدي)',
        'in_accounting' => 'في المحاسبة',
        'delivered' => 'مرتجع مع السائق',
        'returned' => 'مرتجع لدى شركة التوصيل',
        'return' => 'قيد الإرجاع',
        'cancel' => 'ملغى',
        'cancelled' => 'ملغى',
        'close' => 'مكتمل',
        'closed' => 'مكتمل',
    ];

    /** التسمية العربية لحالة أوبتيموس (أو «—» إن غابت). */
    public static function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return self::LABELS[strtolower(trim($status))] ?? $status;
    }

    /**
     * خيارات الفلترة (حالات مميّزة للعرض) — مفتاح ⇒ تسمية.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'submitted' => 'بانتظار الاستلام',
            'pending' => 'معلّق',
            'picked_up' => 'استلمها المندوب',
            'cod_pickup' => 'سُلّم (تحصيل نقدي)',
            'in_accounting' => 'في المحاسبة',
            'delivered' => 'مرتجع مع السائق',
            'returned' => 'مرتجع لدى شركة التوصيل',
            'cancelled' => 'ملغى',
            'closed' => 'مكتمل',
        ];
    }
}
