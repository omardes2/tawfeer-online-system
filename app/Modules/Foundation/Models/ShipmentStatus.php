<?php

namespace App\Modules\Foundation\Models;

/**
 * حالة قابلة للإدارة (المبدأ 10 في ARCHITECTURE.md).
 */
class ShipmentStatus extends ManageableStatus
{
    protected $table = 'shipment_statuses';
}
