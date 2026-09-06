<?php

namespace App\Http\Requests\Purchasing;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseInvoice;
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

        $this->dropUntouchedRows();
    }

    /**
     * يُسقط الصفوف المُضافة التي لم تُملأ قطّ.
     *
     * زرّ «إضافة بند» يضع صفًّا بكميّة ١ وكلفة صفر، فالصفّ الذي يضيفه المستخدم
     * ثم يتركه **لا يبدو فارغًا للتحقّق**: يمرّ على الكمية ويسقط على الاسم
     * برسالةٍ عن `items.10.new_name` لا يفهمها أحد ولا تدلّ على صفٍّ في الشاشة.
     *
     * ويُسقَط الصفّ **بشرطين معًا**: لا يُعرّف صنفًا (لا متغيّر ولا اسم جديد ولا
     * وصف)، ولا يحمل مبلغًا كُتب. فما كُتب فيه رقمٌ لا يُمحى بصمت — يُرفض
     * برسالةٍ تُسمّي صفَّه، لأن كلفةً بلا صنفٍ تُقيَّد عليه لا معنى لها.
     *
     * والكميّة خارج الشرط: قيمتها الافتراضية ١ فوجودُها ليس دليلَ إدخال.
     */
    private function dropUntouchedRows(): void
    {
        $items = $this->input('items');

        if (! is_array($items) || $items === []) {
            return;
        }

        $kept = array_values(array_filter(
            $items,
            fn ($item) => ! is_array($item) || ! $this->isUntouched($item),
        ));

        // لا تُكتب `items` إن لم يتغيّر شيء: الكتابة بلا داعٍ تُعيد ترتيب
        // المفاتيح فتُزحزح أرقام الصفوف في رسائل الأخطاء بلا سبب.
        if (count($kept) !== count($items)) {
            $this->merge(['items' => $kept]);
        }
    }

    /** @param  array<string, mixed>  $item */
    private function isUntouched(array $item): bool
    {
        if ($this->identifiesAnItem($item)) {
            return false;
        }

        foreach (['unit_cost', 'sell_price', 'unit_price_foreign', 'landed_unit_cost'] as $field) {
            if ((float) ($item[$field] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /** الصفّ يدلّ على صنف: متغيّر مختار، أو اسم منتجٍ جديد، أو وصفٌ حرّ. */
    private function identifiesAnItem(array $item): bool
    {
        return filled($item['variant_id'] ?? null)
            || filled($item['new_name'] ?? null)
            || filled($item['description'] ?? null);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'import_shipment_id' => ['nullable', 'integer', 'exists:import_shipments,id'],
            'kind' => ['nullable', Rule::in(['goods', 'expenses'])],
            // تصنيف المصروف لفاتورة المصاريف وحدها — ويُهمَل مع البضاعة.
            'expense_category' => ['nullable', Rule::in(array_keys(PurchaseInvoice::EXPENSE_CATEGORIES))],
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
            //
            // ولا `required_without_all` هنا: رسالتها تُطبع بلغة القواعد
            // («items.10.new_name … items.10.variant_id») فلا تدلّ على صفٍّ في
            // الشاشة ولا تقول ماذا يُفعل. الشرط نفسه محروسٌ في `withValidator`
            // برسالةٍ عربية تُسمّي رقم الصفّ.
            'items.*.new_name' => ['nullable', 'string', 'max:180'],
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

            $this->rejectItemsWithoutIdentity($v);
            $this->rejectPlaceholderVariants($v);
        });
    }

    /**
     * بندٌ يحمل مبلغًا ولا يدلّ على صنف — يُرفض برسالةٍ تُسمّي صفَّه.
     *
     * كلفةٌ بلا صنفٍ تُقيَّد عليه لا تدخل المخزون ولا تُسعّر شيئًا، فتصير رقمًا
     * في الفاتورة بلا أثر. والصفوف غير المملوءة أصلًا سقطت قبل التحقّق.
     */
    private function rejectItemsWithoutIdentity(Validator $v): void
    {
        foreach ((array) $this->input('items', []) as $i => $item) {
            if (! is_array($item) || $this->identifiesAnItem($item)) {
                continue;
            }

            $v->errors()->add("items.{$i}.new_name", __(
                'البند رقم :row: اختر صنفًا من القائمة، أو علّم «صنف جديد» واكتب اسمه، أو اكتب وصفًا — وإلّا احذف البند.',
                ['row' => (int) $i + 1],
            ));
        }
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
            // تظهر حين تسقط كل الصفوف لأنها لم تُملأ — لا حين لا يُرسَل الحقل.
            'items.required' => __('أضف بندًا واحدًا على الأقل — الفاتورة بلا بنود لا تُقيَّد.'),
            'items.min' => __('أضف بندًا واحدًا على الأقل — الفاتورة بلا بنود لا تُقيَّد.'),
            'items.*.qty.required' => __('أدخل الكمية.'),
            'items.*.qty.gt' => __('الكمية يجب أن تكون أكبر من صفر.'),
            'items.*.unit_cost.required' => __('أدخل كلفة الوحدة.'),
        ];
    }
}
