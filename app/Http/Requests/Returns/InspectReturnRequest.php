<?php

namespace App\Http\Requests\Returns;

use App\Modules\Returns\Support\ReturnWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فحص وتوجيه بنود المرتجع (Phase 4.4 / ADR-040).
 */
class InspectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // can:returns.inspect على المسار.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inspections' => ['required', 'array', 'min:1'],
            'inspections.*.item_id' => ['required', 'integer'],
            'inspections.*.inspection_result' => ['required', Rule::in(ReturnWorkflow::INSPECTION_RESULTS)],
            'inspections.*.inventory_route' => ['required', Rule::in(ReturnWorkflow::INVENTORY_ROUTES)],
        ];
    }
}
