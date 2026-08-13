<?php

namespace App\Http\Requests\Store;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\DeliveryCityRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * تحديث بيانات جلسة الإتمام تدريجيًا (كل الحقول اختيارية، تُتحقّق إن حضرت).
 */
class UpdateCheckoutSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الملكية تُفحص في المتحكّم (هوية المتجر).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // المدن المقبولة هي المسعّرة والمفعّلة فقط — نفس ما تعرضه لوحة الإدارة،
            // فلا تصل شركة التوصيل مدينةٌ بلا سعر ولا ربط خارجي.
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::in(
                DeliveryCityRate::where('is_active', true)->pluck('city_id')->filter()->all()
            )],
            'area_id' => ['sometimes', 'nullable', 'integer', 'exists:areas,id'],
            'payment_method_code' => ['sometimes', 'nullable', 'string', 'exists:payment_methods,code'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** المنطقة يجب أن تتبع المدينة المختارة — وإلّا خرجت حمولة شحن غير متّسقة. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $areaId = $this->input('area_id');
            $cityId = $this->input('city_id');
            if ($areaId && $cityId && (int) Area::whereKey($areaId)->value('city_id') !== (int) $cityId) {
                $v->errors()->add('area_id', __('المنطقة المختارة لا تتبع المدينة.'));
            }
        });
    }
}
