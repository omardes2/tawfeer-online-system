<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * منطق أعمال الموردين: جهة اتصال أساسية واحدة كحدّ أقصى (§10).
 */
class SupplierService
{
    public function create(array $data, array $contacts = []): Supplier
    {
        return DB::transaction(function () use ($data, $contacts) {
            $supplier = Supplier::create($data);
            $this->syncContacts($supplier, $contacts);

            // مزامنة القيم الافتراضية من قاعدة البيانات (مثل is_active) للاستجابة.
            $supplier->refresh();

            return $supplier;
        });
    }

    public function update(Supplier $supplier, array $data, ?array $contacts = null): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $contacts) {
            $supplier->update($data);

            if ($contacts !== null) {
                $supplier->contacts()->delete();
                $this->syncContacts($supplier, $contacts);
            }

            return $supplier;
        });
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    private function syncContacts(Supplier $supplier, array $contacts): void
    {
        $primaryTaken = false;

        foreach ($contacts as $contact) {
            $isPrimary = (bool) ($contact['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false; // جهة أساسية واحدة كحدّ أقصى
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $supplier->contacts()->create([
                'name' => $contact['name'],
                'position' => $contact['position'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'is_primary' => $isPrimary,
                'notes' => $contact['notes'] ?? null,
            ]);
        }
    }
}
