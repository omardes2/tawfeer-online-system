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
    /**
     * البيع المباشر صلاحية مستقلّة لا مشتقّة.
     *
     * كانت `create && view`، فيكفي أن ينال دورٌ صلاحية العرض الكاملة لسببٍ آخر
     * حتى تُفتح له نقطة بيع كاملة بلا قصد — وهذا ما يجب ألّا يملكه المسوّق.
     * صلاحية صريحة تُمنح لمن يُقصد منحه، ولا تُستنتج من غيرها.
     */
    public function createDirect(User $user): bool
    {
        return $user->can('sales.orders.create_direct');
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

    /**
     * الإلغاء يخرج من يد منشئ الطلب لحظة تأكيده.
     *
     * قبل التأكيد الطلب مسوّدة عند مُدخِله، وإلغاؤه تصحيحُ خطأ إدخال أو تراجعُ
     * زبون — لا أثر له. بعد التأكيد يكون قد رُحِّل محاسبيًّا وأُرسل لشركة
     * التوصيل، فإلغاؤه يعكس قيودًا ومخزونًا ويُلغي شحنة قائمة؛ قرارٌ يخصّ من
     * يملك التأكيد نفسه لا من أدخل الطلب.
     *
     * غير أن الحدّ الفعلي هو **واقع الطرد** لا حالة الطلب عندنا: ما دام الطرد
     * «بانتظار الاستلام» لدى أوبتيموس فلم يتحرّك شيء بعد، وإلغاؤه تصحيحٌ نظيف
     * يقع عندنا وعند شركة التوصيل معًا. وحين يستلمه المندوب يصير في الطريق
     * فعلًا، فينتقل القرار إلى من يملك التأكيد.
     */
    public function cancel(User $user, Order $m): bool
    {
        if (! $user->can('sales.orders.cancel')) {
            return false;
        }

        return $this->awaitingConfirmation($m)
            || $this->awaitingPickup($m)
            || $user->can('sales.orders.confirm');
    }

    /** لم يُؤكَّد بعد: الحالتان الوحيدتان اللتان يقبلهما `OrderService::confirm`. */
    private function awaitingConfirmation(Order $m): bool
    {
        return $m->confirmed_at === null && in_array($m->status, ['draft', 'new'], true);
    }

    /**
     * الطرد ما زال «بانتظار الاستلام» لدى أوبتيموس.
     *
     * القيم مكرَّرة من `OpostStatus::LABELS` عمدًا لا مقروءةً من تسميتها
     * العربية: ربطُ صلاحيةٍ بنصٍّ معروض يجعل تعديل كلمة في الواجهة يفتح الإلغاء
     * أو يغلقه صامتًا. اختبارٌ يحرس تطابق القائمتين.
     */
    private function awaitingPickup(Order $m): bool
    {
        $status = strtolower(trim((string) $m->latestShipment?->provider_status));

        return in_array($status, self::AWAITING_PICKUP_STATUSES, true);
    }

    /** @var list<string> */
    public const AWAITING_PICKUP_STATUSES = ['submit', 'submitted'];
}
