<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تصنيفات المصروفات — التعريف من اللوحة، والحساب يُفتح تلقائيًا.
 *
 * المستخدم يكتب «عمال تنزيل» فيُفتح له `5100-0001 عمال تنزيل` تحت «مصاريف
 * تشغيلية» في اللحظة نفسها. لا يُطلب منه اختيار رمزٍ محاسبي ولا معرفةُ الدليل —
 * وهذا شرطُ أن تُستعمل الميزة أصلًا.
 *
 * الآلية نفسها المستخدَمة لحسابات الموردين والعملاء (`ensureLedgerAccount`)
 * فلا يفترق مساران لفعلٍ واحد.
 */
class ExpenseCategoryService
{
    /**
     * تصنيف جديد + حسابه.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ExpenseCategory
    {
        $parent = $this->parentAccount();

        return DB::transaction(function () use ($data, $parent) {
            $account = Account::create([
                'code' => $this->nextChildCode($parent),
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'type' => $parent->type,     // مصروف مثل الأب
                'parent_id' => $parent->id,
                'is_postable' => true,       // الترحيل على الطرفي
                'currency' => $parent->currency,
                'is_active' => true,
            ]);

            return ExpenseCategory::create([
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'account_id' => $account->id,
                'is_system' => false,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'notes' => $data['notes'] ?? null,
                // وسمُ «محتسَب من مصدره» — تُعرَض سنداتُه ولا تدخل إجمالي المصاريف.
                'auto_source' => $data['auto_source'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * تعديل تصنيف — الاسم يُزامن على الحساب.
     *
     * وإلا افترق اسمُ الدليل عن اسم القائمة، فقرأ المحاسبُ في ميزان المراجعة
     * اسمًا لا يجده في اللوحة.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $category->update([
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? $category->is_active),
                'sort_order' => (int) ($data['sort_order'] ?? $category->sort_order),
                'notes' => $data['notes'] ?? null,
                // فارغٌ = لا احتساب آليّ. يُمرَّر دائمًا فيُرفع الوسم كما يُوضع.
                'auto_source' => $data['auto_source'] ?? null,
            ]);

            $category->account?->update([
                'name' => $category->name,
                'name_en' => $category->name_en,
            ]);

            return $category;
        });
    }

    /**
     * حذف تصنيف.
     *
     * محروسٌ بأمرين: تصنيفُ النظام مربوطٌ بحسابٍ يُرحّل عليه النظام آليًا فحذفه
     * يقطع مسارًا لا يعرف المستخدم أنه قائم؛ وتصنيفٌ تحرّك حسابُه يترك في
     * التقارير رقمًا بلا اسم. البديل في الحالتين: التعطيل — يختفي من القائمة
     * ويبقى تاريخه.
     *
     * والحساب لا يُحذف أبدًا — يُعطَّل فقط، فحذفُه يترك قيودًا بلا حساب.
     */
    public function delete(ExpenseCategory $category): void
    {
        if ($category->is_system) {
            throw ValidationException::withMessages([
                'category' => __('تصنيف النظام لا يُحذف — يُعطَّل ليختفي من القائمة.'),
            ]);
        }

        if ($category->hasActivity() || $category->vouchers()->exists()) {
            throw ValidationException::withMessages([
                'category' => __('تحرّكت على هذا التصنيف قيود — عطّله بدل حذفه ليبقى تاريخه في التقارير.'),
            ]);
        }

        DB::transaction(function () use ($category) {
            $category->account?->update(['is_active' => false]);
            $category->delete();
        });
    }

    /** أبُ التصنيفات: «مصاريف تشغيلية». */
    private function parentAccount(): Account
    {
        $code = config('accounting.expenses.operating_parent');
        $parent = Account::where('code', $code)->first();

        if (! $parent) {
            throw ValidationException::withMessages([
                'name' => __('حساب «مصاريف تشغيلية» (:code) غير موجود في دليل الحسابات.', ['code' => $code]),
            ]);
        }

        return $parent;
    }

    /**
     * كود فرعي فريد تحت الأب بنمط «5100-0001».
     *
     * يُقاس بأكبر رقمٍ قائم لا بعدد الأبناء: تصنيفٌ حُذف يُنقص العدد فيعيد
     * التسلسل رمزًا مستعملًا، ورمزُ الحساب فريدٌ في الدليل فيسقط الإنشاء.
     */
    private function nextChildCode(Account $parent): string
    {
        $codes = Account::withTrashed()->where('parent_id', $parent->id)->pluck('code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%04d', $parent->code, $max + 1);
    }
}
