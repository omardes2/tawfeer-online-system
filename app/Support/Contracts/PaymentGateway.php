<?php

namespace App\Support\Contracts;

/**
 * عقد بوابة الدفع (المبدأ 13 في ARCHITECTURE.md: طبقة التكامل).
 *
 * لا يُربط أي كود بمزوّد دفع مباشرة؛ بل يعتمد على هذا العقد ويُحقَن
 * التنفيذ (Driver) عبر الـ Container. تبديل المزوّد = إضافة Driver جديد
 * وتغيير الإعداد، دون لمس منطق الأعمال.
 */
interface PaymentGateway
{
    /**
     * إنشاء عملية دفع وإرجاع بياناتها (مثل رابط/معرّف العملية).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function charge(int $amountMinor, string $currency, array $payload = []): array;

    /**
     * التحقّق من حالة عملية دفع.
     */
    public function verify(string $reference): bool;

    /**
     * استرداد كامل أو جزئي.
     */
    public function refund(string $reference, ?int $amountMinor = null): bool;

    /**
     * اسم المزوّد (للتسجيل والتقارير).
     */
    public function name(): string;
}
