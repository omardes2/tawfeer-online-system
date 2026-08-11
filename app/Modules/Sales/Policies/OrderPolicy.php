<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Order;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        // عرض كامل، أو عرض «الخاص» (طلباته هو فقط) — كلاهما يُتيح فتح قائمة الطلبات.
        return $user->can('sales.orders.view') || $user->can('sales.orders.view_own');
    }

    public function view(User $user, Order $m): bool
    {
        return $user->can('sales.orders.view')
            || ($user->can('sales.orders.view_own') && $this->owns($user, $m));
    }

    public function create(User $user): bool
    {
        return $user->can('sales.orders.create');
    }

    /**
     * إنشاء مبيعة مباشرة (نقطة بيع): متاح فقط لأصحاب العرض الكامل (لا لأصحاب «الخاص»)،
     * فلا يظهر بند «مبيعات مباشرة» لموظف المبيعات/المسوّق.
     */
    public function createDirect(User $user): bool
    {
        return $user->can('sales.orders.create') && $user->can('sales.orders.view');
    }

    /** الطلب من إنشاء المستخدم أو مُسنَد إليه أو مسوّقه — لتحديد نطاق «عرض الخاص». */
    private function owns(User $user, Order $order): bool
    {
        return in_array($user->id, [$order->created_by, $order->assigned_to, $order->affiliate_id], true);
    }

    public function update(User $user, Order $m): bool
    {
        return $user->can('sales.orders.update');
    }

    public function delete(User $user, Order $m): bool
    {
        return $user->can('sales.orders.delete');
    }

    /** حذف إداري نهائي (مع عكس الأثر المحاسبي) لأي طلب — لحساب الأدمن فقط. */
    public function forceDelete(User $user, Order $m): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * تحويل فاتورة طلب توصيل إلى «مدفوعة» يدويًا (تجاوز حين لا تصل حالة المزوّد) —
     * لمدير النظام فقط، لأنها تُثبت واقعة قبض دون مرور بمسار التحصيل العادي.
     */
    public function markPaid(User $user, Order $m): bool
    {
        return $user->hasRole('admin');
    }

    public function confirm(User $user, Order $m): bool
    {
        return $user->can('sales.orders.confirm');
    }

    public function reserve(User $user, Order $m): bool
    {
        return $user->can('sales.orders.reserve');
    }

    public function ship(User $user, Order $m): bool
    {
        return $user->can('sales.orders.ship');
    }

    public function deliver(User $user, Order $m): bool
    {
        return $user->can('sales.orders.deliver');
    }

    public function cancel(User $user, Order $m): bool
    {
        return $user->can('sales.orders.cancel');
    }
}
