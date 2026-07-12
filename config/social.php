<?php

return [
    /*
    | المصادقة الاجتماعية (Phase 3.5 / ADR-036). المزوّدون المُفعّلون + بيانات العرض
    | (أيقونة/لون رسمي). الأسرار في config/services.php ← .env حصريًا.
    */
    'providers' => ['google', 'facebook'],

    'display' => [
        'google' => ['label' => 'Google', 'color' => '#ffffff', 'text' => '#3c4043', 'border' => '#dadce0'],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'text' => '#ffffff', 'border' => '#1877F2'],
    ],
];
