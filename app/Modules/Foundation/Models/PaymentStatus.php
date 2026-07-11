<?php

namespace App\Modules\Foundation\Models;

/**
 * حالة قابلة للإدارة (المبدأ 10 في ARCHITECTURE.md).
 */
class PaymentStatus extends ManageableStatus
{
    protected $table = 'payment_statuses';
}
