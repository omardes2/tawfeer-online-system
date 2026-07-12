<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerAddress;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق أعمال العملاء (ADR-030، BR-CUST). كل المنطق هنا لا في المتحكمات.
 */
class CustomerService
{
    public function create(array $data, array $phones = [], array $addresses = [], array $contacts = []): Customer
    {
        // الهاتف اختياري لعملاء الدخول الاجتماعي (يُكمَّل لاحقًا) — يُطبَّع إن وُجد فقط.
        $data['primary_phone'] = filled($data['primary_phone'] ?? null)
            ? $this->normalizePhone($data['primary_phone'])
            : null;

        return DB::transaction(function () use ($data, $phones, $addresses, $contacts) {
            $customer = Customer::create($data + ['created_by' => auth()->id()]);
            $this->syncPhones($customer, $phones);
            $this->syncAddresses($customer, $addresses);
            $this->syncContacts($customer, $contacts);

            return $customer;
        });
    }

    public function update(Customer $customer, array $data, ?array $phones = null, ?array $addresses = null, ?array $contacts = null): Customer
    {
        if (isset($data['primary_phone'])) {
            $data['primary_phone'] = $this->normalizePhone($data['primary_phone']);
        }

        return DB::transaction(function () use ($customer, $data, $phones, $addresses, $contacts) {
            $customer->update($data);

            if ($phones !== null) {
                $customer->phones()->delete();
                $this->syncPhones($customer, $phones);
            }
            if ($addresses !== null) {
                $customer->addresses()->delete();
                $this->syncAddresses($customer, $addresses);
            }
            if ($contacts !== null) {
                $customer->contacts()->delete();
                $this->syncContacts($customer, $contacts);
            }

            return $customer;
        });
    }

    public function delete(Customer $customer): void
    {
        // حذف ناعم فقط لكيان مهم (BR-CUST-13).
        $customer->delete();
    }

    /*
    | إدارة عناوين العميل المستقلّة (Phase 3.4 — تجربة العميل). تُعيد استخدام قاعدة
    | «افتراضي واحد» (BR-CUST-06). كل المنطق هنا، لا تكرار في متحكّمات الواجهة.
    */

    /** @param  array<string, mixed>  $data */
    public function addAddress(Customer $customer, array $data): void
    {
        DB::transaction(function () use ($customer, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false) || $customer->addresses()->count() === 0;
            if ($isDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }
            $customer->addresses()->create($this->addressAttributes($data, $isDefault));
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateAddress(Customer $customer, CustomerAddress $address, array $data): void
    {
        abort_unless($address->customer_id === $customer->id, 403);

        DB::transaction(function () use ($customer, $address, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }
            $address->update($this->addressAttributes($data, $isDefault || $address->is_default));
        });
    }

    public function removeAddress(Customer $customer, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $customer->id, 403);
        $wasDefault = $address->is_default;
        $address->delete();

        // ترقية أوّل عنوان متبقٍّ ليكون الافتراضي إن حُذف الافتراضي.
        if ($wasDefault) {
            $next = $customer->addresses()->orderBy('id')->first();
            $next?->update(['is_default' => true]);
        }
    }

    public function setDefaultAddress(Customer $customer, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $customer->id, 403);
        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });
    }

    /** تحديث تفضيلات العميل (لغة/فرع مفضّل/تفضيلات تواصل — جاهزية تسويق، بلا منطق نمو). */
    public function updatePreferences(Customer $customer, array $data): Customer
    {
        $customer->update(array_intersect_key($data, array_flip([
            'preferred_locale', 'preferred_branch_id', 'communication_preferences',
        ])));

        return $customer->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addressAttributes(array $data, bool $isDefault): array
    {
        return [
            'label' => $data['label'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'governorate_id' => $data['governorate_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'area_id' => $data['area_id'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'is_default' => $isDefault,
        ];
    }

    public function addNote(Customer $customer, string $body): Customer
    {
        $customer->customerNotes()->create([
            'body' => $body,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        return $customer;
    }

    /** حظر العميل (BR-CUST-12) — يمنع إنشاء طلبات جديدة. */
    public function block(Customer $customer, string $reason): Customer
    {
        $customer->update(['is_blocked' => true, 'blocked_reason' => $reason, 'blocked_at' => now()]);

        return $customer;
    }

    public function unblock(Customer $customer): Customer
    {
        $customer->update(['is_blocked' => false, 'blocked_reason' => null, 'blocked_at' => null]);

        return $customer;
    }

    /**
     * دمج عميلين مكرّرين (BR-CUST-14): ينقل الهواتف/العناوين/جهات الاتصال/الملاحظات/الطلبات
     * إلى السجل الباقي، ويعلّم المُدمَج ويحذفه ناعمًا. لا يُفقد أي طلب.
     */
    public function merge(Customer $source, Customer $target): Customer
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['merge' => __('لا يمكن دمج العميل مع نفسه.')]);
        }

        return DB::transaction(function () use ($source, $target) {
            $source->phones()->update(['customer_id' => $target->id, 'is_primary' => false]);
            $source->addresses()->update(['customer_id' => $target->id, 'is_default' => false]);
            $source->contacts()->update(['customer_id' => $target->id, 'is_primary' => false]);
            $source->customerNotes()->update(['customer_id' => $target->id]);
            Order::where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            $source->update(['merged_into_id' => $target->id]);
            $source->delete();

            return $target->fresh();
        });
    }

    /**
     * كشف التكرار بالهاتف (BR-CUST-03/05): يفحص primary_phone وكل أرقام العملاء.
     */
    public function findDuplicatesByPhone(string $phone, ?int $excludeId = null): Collection
    {
        $normalized = $this->normalizePhone($phone);

        return Customer::query()
            ->where(fn ($q) => $q->where('primary_phone', $normalized)
                ->orWhereHas('phones', fn ($p) => $p->where('phone', $normalized)))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();
    }

    /** تطبيع رقم الهاتف: أرقام فقط (إزالة +/فواصل/مسافات) لمطابقة تكرار متّسقة (BR-CUST-03). */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', trim($phone)) ?? $phone;
    }

    private function syncPhones(Customer $customer, array $phones): void
    {
        $primaryTaken = false;
        foreach ($phones as $p) {
            $isPrimary = (bool) ($p['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false;
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $customer->phones()->create([
                'phone' => $this->normalizePhone($p['phone']),
                'label' => $p['label'] ?? null,
                'is_primary' => $isPrimary,
            ]);
        }
    }

    private function syncAddresses(Customer $customer, array $addresses): void
    {
        $defaultTaken = false;
        foreach ($addresses as $a) {
            $isDefault = (bool) ($a['is_default'] ?? false);
            if ($isDefault && $defaultTaken) {
                $isDefault = false;
            }
            $defaultTaken = $defaultTaken || $isDefault;

            $customer->addresses()->create([
                'label' => $a['label'] ?? null,
                'recipient_name' => $a['recipient_name'] ?? null,
                'phone' => isset($a['phone']) ? $this->normalizePhone($a['phone']) : null,
                'governorate_id' => $a['governorate_id'] ?? null,
                'city_id' => $a['city_id'] ?? null,
                'area_id' => $a['area_id'] ?? null,
                'address_line' => $a['address_line'] ?? null,
                'is_default' => $isDefault,
            ]);
        }
    }

    private function syncContacts(Customer $customer, array $contacts): void
    {
        $primaryTaken = false;
        foreach ($contacts as $c) {
            $isPrimary = (bool) ($c['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false;
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $customer->contacts()->create([
                'name' => $c['name'],
                'position' => $c['position'] ?? null,
                'email' => $c['email'] ?? null,
                'phone' => isset($c['phone']) ? $this->normalizePhone($c['phone']) : null,
                'is_primary' => $isPrimary,
                'notes' => $c['notes'] ?? null,
            ]);
        }
    }
}
