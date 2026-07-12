<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Notifications\AccountWelcomeNotification;
use Illuminate\Support\Facades\DB;

/**
 * تجربة حساب العميل (Phase 3.4 / ADR-035): تنسيق التسجيل وربط العميل والدمج وإعادة
 * الطلب — يُعيد استخدام CustomerService/CartService دون تكرار منطق أعمال.
 */
class AccountService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CartService $carts,
    ) {}

    /**
     * تسجيل عميل جديد: إنشاء مستخدم (دور customer) + سجلّ عميل مرتبط + إشعار ترحيبي.
     *
     * @param  array<string, mixed>  $data  name/email/phone/password/birth_date
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $branchId = Branch::default()?->id;

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'branch_id' => $branchId,
                'is_active' => true,
            ]);
            $user->assignRole('customer');

            // سجلّ العميل المرتبط (يُعيد استخدام CustomerService — تطبيع الهاتف/الأبناء).
            $this->customers->create(
                [
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'primary_phone' => $data['phone'] ?? null,
                    'birth_date' => $data['birth_date'],
                    'source' => 'storefront',
                ],
                phones: ! empty($data['phone']) ? [['phone' => $data['phone'], 'is_primary' => true]] : [],
            );

            $user->notify(new AccountWelcomeNotification);

            return $user;
        });
    }

    public function customerFor(User $user): ?Customer
    {
        return Customer::where('user_id', $user->id)->first();
    }

    /** دمج سلة الضيف في سلة المستخدم (عند الدخول/التسجيل). */
    public function mergeGuestCart(User $user, ?string $guestToken): void
    {
        $this->carts->mergeGuestIntoUser($user, $guestToken);
    }

    /**
     * إعادة طلب سابق: إضافة بنوده المتاحة إلى سلة المستخدم (يُعيد استخدام addItem).
     * تُتجاوز البنود غير المتاحة/غير القابلة للبيع بصمت. يُعيد عدد البنود المُضافة.
     */
    public function reorder(User $user, Order $order): int
    {
        $cart = $this->carts->forUser($user);
        $order->loadMissing('items.variant');
        $added = 0;

        foreach ($order->items as $item) {
            if (! $item->variant) {
                continue;
            }
            try {
                $this->carts->addItem($cart, $item->variant, (float) $item->qty);
                $added++;
            } catch (\Throwable $e) {
                // بند غير متاح الآن — يُتجاوز (تُعاد المتاحة فقط).
            }
        }

        return $added;
    }
}
