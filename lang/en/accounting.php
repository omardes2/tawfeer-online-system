<?php

// Operational accounting (Phase 7.1).
return [
    'kind' => [
        'receipt' => 'Receipt Voucher',
        'payment' => 'Payment Voucher',
        'expense' => 'Expense',
        'income' => 'Other Income',
        'transfer' => 'Transfer',
    ],
    'status' => [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'posted' => 'Posted',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed',
    ],
    'counter_account' => [
        'receipt' => 'Credit account (revenue/receivable/…)',
        'payment' => 'Debit account (expense/payable/…)',
        'expense' => 'Expense account',
        'income' => 'Revenue account',
    ],
];
