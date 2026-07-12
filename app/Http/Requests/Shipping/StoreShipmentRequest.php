<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'string', 'exists:orders,uuid'],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'recipient_name' => ['nullable', 'string', 'max:180'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'address_text' => ['nullable', 'string', 'max:1000'],
            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'shipping_zone' => ['nullable', 'string', 'exists:shipping_zones,uuid'],
            'delivery_provider' => ['nullable', 'string', 'exists:delivery_providers,uuid'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'], // تجاوز يدوي — يتطلّب صلاحية (يُفحص في المتحكم)
            'notes' => ['nullable', 'string'],
        ];
    }
}
