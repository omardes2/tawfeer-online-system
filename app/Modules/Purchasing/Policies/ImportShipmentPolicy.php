<?php

namespace App\Modules\Purchasing\Policies;

use App\Models\User;
use App\Modules\Purchasing\Models\ImportShipment;

/**
 * الإغلاق صلاحية منفصلة عن الإدارة: تعديل بيانات شحنة عملٌ إداري، أمّا إغلاقها
 * فيُنشئ قيدًا يُقفل فرق التقدير في حساب نتيجة.
 */
class ImportShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.shipments.view');
    }

    public function view(User $user, ImportShipment $m): bool
    {
        return $user->can('purchasing.shipments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.shipments.manage');
    }

    public function update(User $user, ImportShipment $m): bool
    {
        return $user->can('purchasing.shipments.manage') && $m->isOpen();
    }

    public function delete(User $user, ImportShipment $m): bool
    {
        return $user->can('purchasing.shipments.manage') && $m->isOpen();
    }

    public function close(User $user, ImportShipment $m): bool
    {
        return $user->can('purchasing.shipments.close') && $m->isOpen();
    }

    public function reopen(User $user, ImportShipment $m): bool
    {
        return $user->can('purchasing.shipments.close') && ! $m->isOpen();
    }
}
