<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * منطق أعمال الموردين: جهة اتصال أساسية واحدة كحدّ أقصى (§10)، وحساب فرعي محاسبي
 * لكل مورد تحت «ذمم الموردين» — تُرحَّل عليه قيود فواتير/مدفوعات المورد.
 */
class SupplierService
{
    public function create(array $data, array $contacts = []): Supplier
    {
        return DB::transaction(function () use ($data, $contacts) {
            // رمز تسلسلي تلقائي يبدأ من 1000 عند عدم تمريره (نموذج الإضافة لا يرسله).
            if (empty($data['code'])) {
                $data['code'] = $this->nextCode();
            }
            $supplier = Supplier::create($data);
            $this->syncContacts($supplier, $contacts);
            $this->ensureLedgerAccount($supplier);

            // مزامنة القيم الافتراضية من قاعدة البيانات (مثل is_active) للاستجابة.
            $supplier->refresh();

            return $supplier;
        });
    }

    /**
     * الرمز التسلسلي التالي للمورد: أكبر رمز رقمي + 1، بدءًا من 1000.
     *
     * **يشمل المحذوفين ناعمًا** لأن قيد التفرّد في قاعدة البيانات يشملهم: بدون
     * `withTrashed()` يُعاد استخدام رمز موردٍ محذوف فيفشل الإدراج بـUniqueConstraintViolation.
     */
    public function nextCode(): string
    {
        $max = Supplier::withTrashed()->pluck('code')
            ->filter(fn ($c) => ctype_digit((string) $c))
            ->map(fn ($c) => (int) $c)
            ->max();

        $next = max(1000, ($max ?? 999) + 1);
        while (Supplier::withTrashed()->where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    public function update(Supplier $supplier, array $data, ?array $contacts = null): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $contacts) {
            $supplier->update($data);
            $this->ensureLedgerAccount($supplier); // ينشئه إن غاب، ويزامن الاسم إن تغيّر

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

    /**
     * يضمن وجود حساب فرعي للمورد تحت «ذمم الموردين» (الحساب الأب من الإعدادات).
     * idempotent: ينشئ الحساب مرّة واحدة ويربطه، ثم يزامن الاسم عند تغيّره.
     */
    public function ensureLedgerAccount(Supplier $supplier): ?Account
    {
        $parent = Account::where('code', config('accounting.purchasing.payable_account'))->first();
        if (! $parent) {
            return null; // دليل الحسابات غير مُهيّأ بعد — لا نُعطّل إنشاء المورد.
        }

        // موجود مسبقًا: نُزامن الاسم فقط إن تغيّر.
        if ($supplier->gl_account_id && ($account = $supplier->glAccount()->first())) {
            $name = $this->accountName($supplier);
            if ($account->name !== $name) {
                $account->update(['name' => $name]);
            }

            return $account;
        }

        $account = Account::create([
            'code' => $this->nextChildCode($parent),
            'name' => $this->accountName($supplier),
            'type' => $parent->type,               // خصم (liability) مثل الأب
            'parent_id' => $parent->id,
            'is_postable' => true,                 // الترحيل يكون على الفرعي
            'currency' => $parent->currency,
            'is_active' => true,
        ]);

        $supplier->forceFill(['gl_account_id' => $account->id])->save();

        return $account;
    }

    private function accountName(Supplier $supplier): string
    {
        return __('ذمم المورد: :name', ['name' => $supplier->name]);
    }

    /** كود فرعي فريد تحت الأب بنمط «2010-0001». */
    private function nextChildCode(Account $parent): string
    {
        $seq = (int) Account::where('parent_id', $parent->id)->count() + 1;

        do {
            $code = $parent->code.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Account::where('code', $code)->exists());

        return $code;
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
