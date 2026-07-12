<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إدخال طلب مُساعد (Phase 4.1). القناة/العميل/الفرع/المستودع/البنود بأسعار قابلة للتعديل.
 */
class AssistedOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية عبر can:sales.orders.assist على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['manual', 'marketer', 'pos', 'whatsapp', 'instagram', 'messenger', 'phone', 'other'])],
            'customer_name' => ['required', 'string', 'max:180'],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'branch_id' => ['required', 'exists:branches,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'affiliate_id' => ['nullable', 'exists:users,id'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant' => ['required', 'string', 'exists:product_variants,uuid'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'items.*.discount' => ['nullable', 'numeric', 'gte:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
