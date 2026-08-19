<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * عتبات الحكم في «الميزانية اليومية».
 *
 * ترتيب العتبات الثلاث تصاعديًّا **شرط صحّة لا ذوق**: الحكم `match` يفحصها
 * بالترتيب، فلو ساوت عتبةُ «زد» عتبةَ «ثبّت» أو تجاوزتها ابتلع الفرعُ الأول
 * ما بعده، فلا يصدر «ثبّت» أبدًا مهما كانت تكلفة الطلب.
 */
class StoreAdThresholdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.ad_budget.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'window_days' => ['required', 'integer', 'min:1', 'max:30'],
            'min_orders' => ['required', 'integer', 'min:1', 'max:100'],
            'cpa_increase_below' => ['required', 'numeric', 'min:1', 'max:100000'],
            'cpa_hold_below' => ['required', 'numeric', 'min:1', 'max:100000'],
            'cpa_reduce_below' => ['required', 'numeric', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $increase = (float) $this->input('cpa_increase_below');
            $hold = (float) $this->input('cpa_hold_below');
            $reduce = (float) $this->input('cpa_reduce_below');

            if ($increase >= $hold || $hold >= $reduce) {
                $v->errors()->add('cpa_hold_below', __('العتبات تتصاعد: «زد» أقلّ من «ثبّت» أقلّ من «أنقص».'));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'window_days' => __('نافذة الحكم'),
            'min_orders' => __('أقلّ عدد طلبات'),
            'cpa_increase_below' => __('عتبة «زد»'),
            'cpa_hold_below' => __('عتبة «ثبّت»'),
            'cpa_reduce_below' => __('عتبة «أنقص»'),
        ];
    }
}
