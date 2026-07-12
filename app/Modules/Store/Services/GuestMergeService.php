<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Sales\Models\Order;

/**
 * دمج بيانات الضيف عند الدخول/التسجيل (Phase 3.5 / ADR-036). يُعيد استخدام
 * CartService/CustomerService — لا منطق أعمال جديد. يوحّد: دمج السلة، ربط طلبات
 * الضيف المؤهّلة (قاعدة CRM: تطابق الهاتف المطبّع)، مع الحفاظ على العناوين/الإشعارات.
 */
class GuestMergeService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CustomerService $customers,
    ) {}

    public function merge(User $user, ?string $guestToken): void
    {
        // 1) دمج سلة الضيف في سلة المستخدم (3.4/3.5).
        $this->carts->mergeGuestIntoUser($user, $guestToken);

        $customer = Customer::where('user_id', $user->id)->first();
        if (! $customer) {
            return;
        }

        // 2) ربط طلبات الضيف المؤهّلة (قاعدة CRM — التطابق بالهاتف المطبّع).
        $this->linkGuestOrders($customer);

        // 3) العناوين/الإشعارات مملوكة للعميل/المستخدم — محفوظة بطبيعتها (لا دمج مطلوب).
        // 4) المفضّلة: لا مفضّلة للضيف (تتطلّب حسابًا) — نقطة امتداد مستقبلية، لا شيء يُدمج.
    }

    /**
     * ربط الطلبات بلا عميل (طلبات ضيف) ذات الهاتف المطابق للعميل الجديد.
     * التطابق بالهاتف المطبّع (أرقام فقط) اتساقًا مع كشف تكرار CRM (BR-CUST-03/05).
     */
    private function linkGuestOrders(Customer $customer): void
    {
        if (! filled($customer->primary_phone)) {
            return;
        }
        $target = $this->customers->normalizePhone($customer->primary_phone);

        Order::whereNull('customer_id')->get()->each(function (Order $order) use ($target, $customer) {
            if ($this->customers->normalizePhone((string) $order->customer_phone) === $target) {
                $order->update(['customer_id' => $customer->id]);
            }
        });
    }
}
