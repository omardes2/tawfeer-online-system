<?php

namespace App\Modules\Foundation\Models;

/**
 * حالة قابلة للإدارة (المبدأ 10 في ARCHITECTURE.md).
 */
class OrderStatus extends ManageableStatus
{
    protected $table = 'order_statuses';
}
