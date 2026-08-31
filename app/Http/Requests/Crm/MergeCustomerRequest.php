<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
                السجلّ الباقي: موجودٌ وحيّ وغير مدموج.

                و`whereNull('merged_into_id')` هنا لا في الخدمة وحدها: الدمج في
                سجلٍّ مدموج يبني سلسلةً تنتهي عند محذوف، فيضيع أثر العميل عند
                من يتتبّعه من الطلب القديم.
            */
            'target' => ['required', 'string', Rule::exists('customers', 'uuid')
                ->whereNull('deleted_at')->whereNull('merged_into_id')],
        ];
    }

    public function attributes(): array
    {
        return ['target' => __('السجلّ الباقي')];
    }
}
