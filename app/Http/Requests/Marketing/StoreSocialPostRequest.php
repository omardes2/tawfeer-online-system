<?php

namespace App\Http\Requests\Marketing;

use App\Modules\Marketing\Models\SocialPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * حفظ منشور — التحقّق هنا لا في المتحكّم (اصطلاح المشروع).
 */
class StoreSocialPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('marketing.social.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'ad_channel_id' => ['nullable', 'integer', 'exists:ad_channels,id'],
            'platform' => ['required', Rule::in(array_keys(SocialPost::PLATFORMS))],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'tone' => ['nullable', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:5000'],
            'hashtags' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(array_keys(SocialPost::STATUSES))],
            'ai_model' => ['nullable', 'string', 'max:60'],
            'ai_status' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'body' => __('نصّ المنشور'),
            'platform' => __('المنصّة'),
            'ad_channel_id' => __('الصفحة'),
            'product_id' => __('الصنف'),
        ];
    }
}
