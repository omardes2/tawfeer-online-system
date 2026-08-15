<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.invoices.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'supplier_reference' => ['nullable', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:500'],

            // رأس الاستيراد — سعرا الصرف يأتيان معًا أو لا يأتيان: أحدهما وحده
            // لا يكفي للتحويل، وتركُهما فارغين يعني «فاتورة محلية».
            'fx_rate_to_usd' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:usd_rate'],
            'usd_rate' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:fx_rate_to_usd'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cbm_rate_usd' => ['nullable', 'numeric', 'min:0', 'max:1000000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // صنف جديد يُعرَّف من الفاتورة (يُنشأ منتج + متغيّر تلقائيًا).
            'items.*.new_name' => ['nullable', 'required_without_all:items.*.variant_id,items.*.description', 'string', 'max:180'],
            'items.*.sell_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // بنود الاستيراد — تُهمَل ما لم يُملأ سعرا الصرف.
            'items.*.unit_price_foreign' => ['nullable', 'numeric', 'min:0'],
            'items.*.cbm_per_unit' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.landed_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.landed_is_manual' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $base = strtoupper((string) config('app.currency', 'ILS'));
            $currency = strtoupper((string) $this->input('currency', $base));

            // تحويلُ العملة الأساسية إلى نفسها عبر الدولار يُنتج رقمًا خاطئًا
            // بصمت. إمّا عملة أجنبية بسعري صرف، وإمّا فاتورة محلية بلا صرف.
            if ($currency === $base && (float) $this->input('fx_rate_to_usd', 0) > 0) {
                $v->errors()->add('fx_rate_to_usd', __('أسعار الصرف تُملأ للفاتورة بعملة أجنبية فقط — غيّر عملة الفاتورة أو اترك الحقول فارغة.'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fx_rate_to_usd.required_with' => __('أدخل سعر صرف عملة الفاتورة مقابل الدولار.'),
            'usd_rate.required_with' => __('أدخل سعر الدولار مقابل العملة الأساسية.'),
        ];
    }
}
