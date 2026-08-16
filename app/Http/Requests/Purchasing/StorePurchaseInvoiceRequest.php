<?php

namespace App\Http\Requests\Purchasing;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.invoices.create') ?? false;
    }

    /**
     * الصفر في سعري الصرف معناه «غير مُدخَل» لا «سعر صرف يساوي صفرًا».
     *
     * حقول الاستيراد تبقى في صفحة الفاتورة حتى للفاتورة المحلية (تُخفى ولا
     * تُحذف)، فتصل أصفارًا فتسقط على `gt:0` — ورسالة «يجب أن يكون أكبر من صفر»
     * تظهر على حقلين لا يراهما المستخدم أصلًا، فيتعذّر حفظ أي فاتورة بالشيكل.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($rate) => (float) $rate > 0 ? $rate : null,
            $this->only(['fx_rate_to_usd', 'usd_rate']),
        ));
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'import_shipment_id' => ['nullable', 'integer', 'exists:import_shipments,id'],
            'kind' => ['nullable', Rule::in(['goods', 'expenses'])],
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

            // فاتورة المصاريف تُقيَّد على الحساب الوسيط، وبلا شحنة لا يُعرف أيّ
            // تقديرٍ تُطفئ — فيصير الحساب رقمًا مجمَّعًا لا يُغلق أبدًا.
            if ($this->input('kind') === 'expenses' && empty($this->input('import_shipment_id'))) {
                $v->errors()->add('import_shipment_id', __('اختر الشحنة التي تخصّها فاتورة المصاريف.'));
            }

            $this->rejectPlaceholderVariants($v);
        });
    }

    /**
     * يمنع إدخال بضاعة على المتغيّر الافتراضي المجرّد لمنتجٍ له مقاسات/ألوان.
     *
     * ذلك المتغيّر حاملٌ فارغ لا صنفٌ يُباع: الرصيد الداخل عليه لا ينتمي لأيّ
     * مقاس، فلا يظهر للزبون ولا يُحجَز في طلب، ويُفسد توزيعَ المصفوفة لاحقًا.
     * القائمة تُخفيه أصلًا، وهذا حارسُ المسارات الأخرى (نموذج قديم، طلب مباشر).
     */
    private function rejectPlaceholderVariants(Validator $v): void
    {
        $ids = collect($this->input('items', []))->pluck('variant_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return;
        }

        $variants = ProductVariant::with('attributeValues')->whereIn('id', $ids)->get();
        $placeholders = $variants->filter(fn (ProductVariant $variant) => $variant->attributeValues->isEmpty()
            && ProductVariant::where('product_id', $variant->product_id)->whereHas('attributeValues')->exists());

        foreach ($placeholders as $variant) {
            $index = collect($this->input('items', []))
                ->search(fn ($item) => (int) ($item['variant_id'] ?? 0) === (int) $variant->id);

            $v->errors()->add("items.{$index}.variant_id", __(
                'هذا الصنف له مقاسات/ألوان — اختر المقاس المحدَّد بدل الصنف المجرَّد، وإلا دخلت البضاعة رصيدًا لا ينتمي لأيّ مقاس.',
            ));
        }
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
