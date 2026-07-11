<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Validation\ValidationException;

class UnitService
{
    public function create(array $data): Unit
    {
        return Unit::create($data);
    }

    public function update(Unit $unit, array $data): Unit
    {
        // منع جعل الوحدة وحدةً أساسية لنفسها.
        if (array_key_exists('base_unit_id', $data) && (int) $data['base_unit_id'] === $unit->id) {
            throw ValidationException::withMessages(['base_unit_id' => __('لا يمكن أن تكون الوحدة أساسًا لنفسها.')]);
        }

        $unit->update($data);

        return $unit;
    }

    public function delete(Unit $unit): void
    {
        if ($unit->derivedUnits()->exists()) {
            throw ValidationException::withMessages([
                'unit' => __('لا يمكن حذف وحدة أساسية مرتبطة بوحدات أخرى.'),
            ]);
        }

        $unit->delete();
    }
}
