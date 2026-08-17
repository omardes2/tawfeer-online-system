<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ضبط الطيّار الآلي — التحقّق هنا لا في المتحكّم (اصطلاح المشروع).
 *
 * والحدود ليست شكليّة: `max_decrease_pct` مسقوفٌ عند 50 لأن ما فوقه يُعيد
 * المجموعة إلى مرحلة التعلّم بلا فائدة، و`daily_cap` مسقوفٌ بسقفٍ أعلى ظاهر
 * حتى لا يُحوّل صفرٌ زائد قيمةً معقولة إلى ميزانية شهر.
 */
class AutopilotSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('marketing.autopilot.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['required', Rule::in(['suggest', 'brake'])],
            'daily_cap' => ['required', 'numeric', 'min:0', 'max:100000'],
            'max_decrease_pct' => ['required', 'integer', 'min:5', 'max:50'],
            'cooldown_days' => ['required', 'integer', 'min:0', 'max:14'],
            'min_budget' => ['required', 'numeric', 'min:0', 'max:10000'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['integer', 'exists:ad_channels,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'mode' => __('وضع التشغيل'),
            'daily_cap' => __('السقف اليومي'),
            'max_decrease_pct' => __('أقصى نسبة تخفيض'),
            'cooldown_days' => __('أيام التهدئة'),
            'min_budget' => __('أدنى ميزانية يومية'),
        ];
    }
}
