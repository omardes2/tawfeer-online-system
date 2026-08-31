<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تحقّق بند دليل الحسابات.
 *
 * **لا `type` ولا `parent_id` عند التعديل**: النوع يُورَث من الأب لحظة الإنشاء،
 * والأب لا يُنقل بعدها. قبولُهما هنا يفتح بابًا يُعيد كتابة تاريخٍ مُرحَّل —
 * فتنتقل أرصدةُ سنواتٍ ماضية إلى بابٍ آخر بلا قيدٍ يفسّر النقلة.
 */
class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية تُفحص في المتحكّم.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->routeIs('*.store');

        return [
            'parent_id' => [$creating ? 'required' : 'nullable', 'integer', 'exists:accounts,id'],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            // الرمز اختياريّ: يُقترح تاليًا تحت الأب حين يُترك فارغًا.
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'parent_id' => __('الحساب الأب'),
            'name' => __('اسم الحساب'),
            'name_en' => __('الاسم بالإنجليزية'),
            'code' => __('الرمز'),
            'currency' => __('العملة'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => __('الرمز يقبل الأرقام والحروف اللاتينية والشرطة فقط.'),
        ];
    }
}
