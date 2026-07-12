<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تحقّق إنشاء حملة تسويق (Phase 6 / ADR-046).
 */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.campaigns.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'use_case' => ['required', 'string', 'max:40'],
            'channel' => ['required', Rule::in(['whatsapp', 'email', 'sms', 'internal'])],
            'trigger_type' => ['required', Rule::in(['event', 'scheduled', 'manual'])],
            'trigger_event' => ['nullable', 'required_if:trigger_type,event', 'string', 'max:60'],
            'scheduled_at' => ['nullable', 'date'],
            'template_id' => ['nullable', 'integer', 'exists:campaign_templates,id'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_ar' => ['required_without:template_id', 'nullable', 'string', 'max:5000'],
            'body_en' => ['nullable', 'string', 'max:5000'],
            'delay_minutes' => ['nullable', 'integer', 'min:0'],
            'frequency_cap_days' => ['nullable', 'integer', 'min:0'],
            'quiet_start' => ['nullable', 'integer', 'between:0,23'],
            'quiet_end' => ['nullable', 'integer', 'between:0,23'],
            'audience' => ['nullable', 'array'],
        ];
    }
}
