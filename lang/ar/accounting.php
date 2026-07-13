<?php

// المحاسبة التشغيلية (Phase 7.1).
return [
    'kind' => [
        'receipt' => 'سند قبض',
        'payment' => 'سند صرف',
        'expense' => 'مصروف',
        'income' => 'إيراد آخر',
        'transfer' => 'تحويل',
    ],
    'status' => [
        'draft' => 'مسودّة',
        'approved' => 'معتمَد',
        'posted' => 'مُرحّل',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغى',
        'reversed' => 'معكوس',
    ],
    'counter_account' => [
        'receipt' => 'الحساب الدائن (إيراد/ذمم/…)',
        'payment' => 'الحساب المدين (مصروف/ذمم/…)',
        'expense' => 'حساب المصروف',
        'income' => 'حساب الإيراد',
    ],
];
