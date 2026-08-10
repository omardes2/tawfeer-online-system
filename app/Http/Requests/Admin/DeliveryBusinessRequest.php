<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.users.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('delivery_business')?->id;

        return [
            'name' => ['required', 'string', 'max:200'],
            'external_id' => [
                'required', 'string', 'max:100',
                Rule::unique('delivery_businesses', 'external_id')
                    ->where('provider', $this->providerCode())
                    ->ignore($id),
            ],
            'address_external_id' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** كود المزوّد الفعّال (وإلا opost) — يُخزَّن مع البزنس المُضاف يدويًا. */
    public function providerCode(): string
    {
        // env('SHIPPING_PROVIDER=null') يُفسَّر كـnull فعلي؛ نطبّعه إلى السنتينل 'null'.
        $active = config('shipping.provider') ?: 'null';

        return $active !== 'null' ? $active : 'opost';
    }
}
