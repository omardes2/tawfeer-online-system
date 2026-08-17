<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\MarketingContact;
use Illuminate\Support\Facades\DB;

/**
 * استيراد قائمة الأرقام إلى جهات الاتصال التسويقية.
 *
 * أربعة قرارات تحكم هذا الملف:
 *
 * 1. **التطبيع قبل كل شيء.** الرقم نفسه يَرِد في الملف الواحد «0599…»
 *    و«+970 599…» و«00970599…» و«599…». وبلا صيغةٍ موحّدة يدخل أربع مرّات،
 *    فتصل الرسالة أربعًا إلى شخصٍ واحد — وهو أسرع طريقٍ إلى الحجب ثم الحظر.
 *
 * 2. **الاستيراد لا يُنشئ موافقة.** ما يختاره المستورِد يُسجَّل بوصفه **إقرار
 *    تاجر** لا موافقة زبون، ومعه أساسُه نصًّا. والافتراض «غير معروفة».
 *
 * 3. **ما كان موجودًا لا يُدهَس.** رقمٌ مستورَد سابقًا قد يكون انسحب أو حجبنا،
 *    وإعادةُ الاستيراد لا يجوز أن تُعيده إلى قائمة المُراسَلين. تُحدَّث البيانات
 *    الوصفية وحدها، وتبقى حالتا `opted_out` و`blocked_at` كما هي.
 *
 * 4. **المطابقة بعميلٍ قائم إن وُجد.** الشخص الذي اشترى منك مرّة له سجلّ عميل،
 *    وربطُه يمنع ازدواجه ويجعل شرائح الحملات (اشترى/لم يشترِ) ممكنة.
 */
class ContactImportService
{
    /** حجم الدفعة عند الكتابة — خمسة عشر ألف صفٍّ صفًّا صفًّا استعلامٌ لكلٍّ منها. */
    private const CHUNK = 500;

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  صفوف الملف بعد ربط الأعمدة
     * @return array{imported: int, updated: int, invalid: int, duplicates: int, matched: int, samples: array<int, string>}
     */
    public function import(
        iterable $rows,
        string $sourceRef,
        string $consentState,
        ?string $consentBasis,
        ?int $userId,
    ): array {
        $summary = ['imported' => 0, 'updated' => 0, 'invalid' => 0, 'duplicates' => 0, 'matched' => 0, 'samples' => []];
        $seen = [];
        $batch = [];

        foreach ($rows as $row) {
            $raw = trim((string) ($row['phone'] ?? ''));
            $phone = $this->normalize($raw);

            if ($phone === null) {
                $summary['invalid']++;
                // عيّنة من المرفوض تُعرَض للمستخدم: رقمٌ واحد خاطئ في الملف
                // يعني غالبًا عمودًا خاطئًا، لا صفًّا خاطئًا.
                if (count($summary['samples']) < 5 && $raw !== '') {
                    $summary['samples'][] = $raw;
                }

                continue;
            }

            // تكرارٌ داخل الملف نفسه — يُحسب ولا يُكتب مرّتين.
            if (isset($seen[$phone])) {
                $summary['duplicates']++;

                continue;
            }
            $seen[$phone] = true;

            $batch[] = [
                'phone' => $phone,
                'raw' => $raw,
                'name' => $this->clean($row['name'] ?? null, 160),
                'extra' => array_filter([
                    'city' => $this->clean($row['city'] ?? null, 80),
                    'note' => $this->clean($row['note'] ?? null, 255),
                ], fn ($v) => $v !== null),
            ];

            if (count($batch) >= self::CHUNK) {
                $this->writeBatch($batch, $sourceRef, $consentState, $consentBasis, $userId, $summary);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->writeBatch($batch, $sourceRef, $consentState, $consentBasis, $userId, $summary);
        }

        return $summary;
    }

    /**
     * كتابة دفعة — داخل معاملة، ومع مطابقة العملاء باستعلامٍ واحد للدفعة.
     *
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<string, mixed>  $summary
     */
    private function writeBatch(array $batch, string $sourceRef, string $consentState, ?string $consentBasis, ?int $userId, array &$summary): void
    {
        $phones = array_column($batch, 'phone');

        $existing = MarketingContact::whereIn('phone', $phones)->get()->keyBy('phone');
        $customers = $this->matchCustomers($phones);

        DB::transaction(function () use ($batch, $existing, $customers, $sourceRef, $consentState, $consentBasis, $userId, &$summary) {
            foreach ($batch as $item) {
                $customerId = $customers[$item['phone']] ?? null;

                if ($customerId) {
                    $summary['matched']++;
                }

                $contact = $existing->get($item['phone']);

                if ($contact) {
                    // القرار 3: الوصفيّ يُحدَّث، وحالةُ الانسحاب والحجب لا تُمسّ.
                    $contact->fill(array_filter([
                        'name' => $item['name'] ?? $contact->name,
                        'customer_id' => $customerId ?? $contact->customer_id,
                        'extra' => $item['extra'] ?: $contact->extra,
                    ], fn ($v) => $v !== null));

                    if ($contact->consent_state === MarketingContact::CONSENT_UNKNOWN) {
                        $contact->consent_state = $consentState;
                        $contact->consent_basis = $consentBasis;
                        $contact->consent_at = now();
                    }

                    $contact->save();
                    $summary['updated']++;

                    continue;
                }

                MarketingContact::create([
                    'phone' => $item['phone'],
                    'phone_raw' => $item['raw'],
                    'name' => $item['name'],
                    'customer_id' => $customerId,
                    'source' => 'import',
                    'source_ref' => $sourceRef,
                    'consent_state' => $consentState,
                    'consent_basis' => $consentBasis,
                    'consent_at' => $consentState === MarketingContact::CONSENT_UNKNOWN ? null : now(),
                    'extra' => $item['extra'] ?: null,
                    'imported_by' => $userId,
                ]);

                $summary['imported']++;
            }
        });
    }

    /**
     * مطابقة الأرقام بعملاء قائمين.
     *
     * أرقام العملاء مخزَّنة بصيغتها المُدخَلة (أرقامٌ فقط بلا مفتاح دولةٍ غالبًا)،
     * فتُطابَق **الصيغة المحلّية والدولية معًا** — البحث بواحدةٍ منهما يُسقط
     * نصف المطابقات بصمت.
     *
     * @param  array<int, string>  $phones
     * @return array<string, int>
     */
    private function matchCustomers(array $phones): array
    {
        $variants = [];

        foreach ($phones as $phone) {
            foreach ($this->variantsOf($phone) as $variant) {
                $variants[$variant] = $phone;
            }
        }

        if ($variants === []) {
            return [];
        }

        $matched = [];

        Customer::whereIn('primary_phone', array_keys($variants))
            ->get(['id', 'primary_phone'])
            ->each(function (Customer $customer) use ($variants, &$matched) {
                $canonical = $variants[$customer->primary_phone] ?? null;

                if ($canonical && ! isset($matched[$canonical])) {
                    $matched[$canonical] = $customer->id;
                }
            });

        return $matched;
    }

    /**
     * الصيغة الموحّدة للرقم: أرقامٌ فقط بمفتاح الدولة.
     *
     * يعود `null` لما لا يصلح رقمًا — والرفض هنا أرحم من إرسال رسالةٍ إلى رقمٍ
     * لا وجود له، فالرسائل الفاشلة تُحسب على جودة الرقم أيضًا.
     */
    public function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        $country = (string) config('messaging.country_code', '970');
        $explicit = false;

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $explicit = true;
        }

        if (str_starts_with($digits, $country) && strlen($digits) > strlen($country)) {
            $national = substr($digits, strlen($country));
            $explicit = true;
        } elseif (str_starts_with($digits, '0')) {
            $national = ltrim($digits, '0');
            $explicit = true;
        } else {
            $national = $digits;
        }

        /*
        | الطول يُقاس على **الرقم الوطني** لا على الناتج بعد إضافة المفتاح: قياسه
        | بعدها يُنقذ القمامة القصيرة — «2026-08-18» يصير ثمانية أرقام فيُضاف
        | إليها المفتاح فتبلغ أحد عشر، وتمرّ رقمَ هاتف.
        |
        | والحدّ الأدنى يختلف بحسب وجود إشارةٍ صريحة أنه هاتف (صفرٌ بادئ أو مفتاح
        | دولة): مع الإشارة ثمانية تكفي — وهو طول الهاتف الأرضي — وبدونها تسعة،
        | فرقمٌ عارٍ من ثمانية خانات تاريخٌ أو مبلغٌ أرجح منه أن يكون هاتفًا.
        */
        $min = $explicit ? 8 : 9;

        return strlen($national) >= $min && strlen($national) <= 12
            ? $country.$national
            : null;
    }

    /**
     * صيغ الرقم المحتملة كما قد تكون مخزَّنة عند العملاء.
     *
     * @return array<int, string>
     */
    private function variantsOf(string $canonical): array
    {
        $country = (string) config('messaging.country_code', '970');
        $local = str_starts_with($canonical, $country) ? substr($canonical, strlen($country)) : $canonical;

        return array_values(array_unique([$canonical, $local, '0'.$local, '00'.$canonical]));
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
