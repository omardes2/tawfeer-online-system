<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إضافة بنودٍ إلى دليل الحسابات وتعديلها — **بأصول المحاسبة لا بحرّية مطلقة**.
 *
 * الدليل ليس قائمة أسماء: بنيتُه هي ما تُبنى عليه الميزانية وقائمة الدخل وميزان
 * المراجعة. فبندٌ يُضاف في غير موضعه لا يُنتج خطأً ظاهرًا — يُنتج تقريرًا يبدو
 * سليمًا وهو كاذب. ولهذا خمس قواعد تُفرض هنا لا تُترك لانتباه المُدخِل:
 *
 * **١. النوع يُورَث من الأب، ولا يُختار.** حسابٌ تحت «الأصول» أصلٌ بالضرورة.
 * ولو سُمح باختياره لظهر مصروفٌ داخل الأصول: يختلّ ميزان المراجعة، ويُحتسب
 * المصروفُ أصلًا فيتضخّم الربح.
 *
 * **٢. الأب يفقد قابلية الترحيل حين يُنجب.** الرصيد يتجمّع من الأوراق إلى
 * الجذر، فحسابٌ له فروعٌ ويُرحَّل عليه مباشرةً يُحتسب مرّتين: مرّةً بمبلغه
 * ومرّةً ضمن مجموع فروعه.
 *
 * **٣. الرمز يبدأ برمز الأب.** الترتيب في الدليل بالرمز، فرمزٌ خارج تسلسل أبيه
 * يظهر تحت أبٍ آخر في كل تقرير — والشجرة تُقرأ بالرمز لا بالمعرّف.
 *
 * **٤. الرمز فريدٌ شاملًا المحذوف ناعمًا.** قيد التفرّد في قاعدة البيانات يشمله،
 * فإعادةُ استعماله تُسقط الإدراج بخطأٍ لا يفهمه المستخدم.
 *
 * **٥. ما تحرّك لا يُحذف ولا يُنقل.** الحساب الذي عليه قيدٌ مُرحَّل جزءٌ من
 * تاريخٍ مُثبَت: يُعطَّل فلا يُستعمل بعدها، ولا يُمحى فتُيتَّم قيوده.
 */
class AccountService
{
    /**
     * إضافة حسابٍ فرعيّ تحت أبٍ قائم.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        $parent = Account::findOrFail($data['parent_id']);
        $code = trim((string) ($data['code'] ?? '')) ?: $this->nextChildCode($parent);

        $this->assertCodeIsFree($code);
        $this->assertCodeSitsUnder($code, $parent);

        return DB::transaction(function () use ($data, $parent, $code) {
            $account = Account::create([
                'code' => $code,
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                // النوع من الأب لا من النموذج — القاعدة ١.
                'type' => $parent->type,
                'parent_id' => $parent->id,
                'is_postable' => true,   // الورقة تُرحَّل عليها، والأب يُقفل أدناه.
                'currency' => $data['currency'] ?? $parent->currency,
                'is_active' => true,
            ]);

            // القاعدة ٢: الأب صار حسابَ مراقبة.
            if ($parent->is_postable) {
                $parent->update(['is_postable' => false]);
            }

            return $account;
        });
    }

    /**
     * تعديل حسابٍ قائم — **الاسم والعملة والتفعيل فقط**.
     *
     * الرمز والنوع والأب لا تُعدَّل: تغييرُ أيّها يُعيد كتابة تاريخٍ مُرحَّل،
     * فتنتقل أرصدةُ سنواتٍ ماضية إلى بابٍ آخر في التقارير بلا قيدٍ يفسّر النقلة.
     * والحساب الخطأ يُعطَّل ويُفتح غيرُه.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        $active = (bool) ($data['is_active'] ?? $account->is_active);

        // لا يُعطَّل حسابٌ له فروعٌ نشطة: تعطيلُ الأب يُخفيه من الاختيار ويترك
        // فروعه معلّقةً تحت أبٍ معطَّل.
        if (! $active && $account->children()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'is_active' => __('لا يُعطَّل حسابٌ له فروع نشطة — عطّل فروعه أولًا.'),
            ]);
        }

        $account->update([
            'name' => $data['name'] ?? $account->name,
            'name_en' => $data['name_en'] ?? $account->name_en,
            'currency' => $data['currency'] ?? $account->currency,
            'is_active' => $active,
        ]);

        return $account;
    }

    /**
     * حذف حسابٍ لم يتحرّك ولا فروعَ له — القاعدة ٥.
     */
    public function delete(Account $account): void
    {
        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => __('لا يُحذف حسابٌ له فروع — احذف فروعه أولًا.'),
            ]);
        }

        if (JournalLine::where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([
                'account' => __('لا يُحذف حسابٌ عليه قيود مُرحَّلة — عطّله بدلًا من ذلك.'),
            ]);
        }

        DB::transaction(function () use ($account) {
            $parent = $account->parent()->first();
            $account->delete();

            // آخرُ فرعٍ يُحذف يُعيد لأبيه قابلية الترحيل: لم يعد حسابَ مراقبة.
            if ($parent && ! $parent->children()->exists()) {
                $parent->update(['is_postable' => true]);
            }
        });
    }

    /**
     * الرمز التالي تحت الأب بنمط «1010-0001» — **شاملًا المحذوف ناعمًا**.
     *
     * يُقترح على الشاشة فيبقى الترقيم متّسقًا بلا أن يخترعه المُدخِل.
     */
    public function nextChildCode(Account $parent): string
    {
        $seq = (int) Account::withTrashed()->where('parent_id', $parent->id)->count() + 1;

        do {
            $code = $parent->code.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Account::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    private function assertCodeIsFree(string $code): void
    {
        if (Account::withTrashed()->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => __('الرمز :code مستعمل — اختر غيره.', ['code' => $code]),
            ]);
        }
    }

    /** القاعدة ٣: الرمز يبدأ برمز الأب، وإلا قُرئ تحت أبٍ آخر في التقارير. */
    private function assertCodeSitsUnder(string $code, Account $parent): void
    {
        if (! str_starts_with($code, $parent->code)) {
            throw ValidationException::withMessages([
                'code' => __('رمز الحساب يجب أن يبدأ برمز أبيه (:parent).', ['parent' => $parent->code]),
            ]);
        }
    }
}
