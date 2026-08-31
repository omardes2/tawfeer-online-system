<?php

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المكافآت والسلف — دفترٌ للموظف خارج عقده.
 *
 * ## لماذا خارج الراتب
 *
 * الراتب رقمٌ في عقدٍ ساري، وكشوفُ الرواتب تُبنى منه. فمكافأةٌ تُضاف إليه
 * تصير راتبًا دائمًا يتكرّر كل شهر، ويتضخّم معها مخصّص نهاية الخدمة (وهو
 * الأساسيّ ÷ ١٢) فيصير التزامًا على الشركة عن مبلغٍ مُنِح مرّةً واحدة.
 *
 * فالمكافأة حدثٌ مستقلّ، والسلفة كذلك، وكلاهما لا يمسّ العقد.
 *
 * ## والفرق بينهما محاسبيّ لا شكليّ
 *
 * **المكافأة مصروف**: خرج المال ولن يعود — مدين «مكافآت وحوافز ٥٢٢٠» / دائن
 * الخزينة.
 *
 * **والسلفة أصل**: خرج المال وهو دَينٌ على الموظف — مدين «سلف الموظفين ١١٥٠»
 * / دائن الخزينة. وتسديدُها يُطفئ الدَّين لا يُنشئ إيرادًا.
 *
 * وقيدُ السلفة مصروفًا هو الخطأ الشائع: يُضخّم كلفةَ الشهر، ويُخفي أصلًا
 * للشركة، ثم يُقرأ التسديد إيرادًا — فيظهر ربحٌ من إقراض الموظفين.
 *
 * ## والمبلغ موجبٌ دائمًا
 *
 * الاتجاه من `kind` لا من الإشارة: `advance` تزيد الدَّين و`advance_repayment`
 * تُنقصه. ودفترٌ فيه سالبٌ وموجب لنوعين مختلفين يُغري بجمعٍ لا معنى له —
 * مكافأةٌ ناقص سلفة ليست رقمًا.
 */
return new class extends Migration
{
    /** [الرمز، الاسم، النوع، رمز الأب] */
    private const ACCOUNTS = [
        ['1150', 'سلف الموظفين', 'asset', '1000'],
        ['5220', 'مكافآت وحوافز', 'expense', '5000'],
    ];

    public function up(): void
    {
        Schema::create('employee_finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                        // bonus | advance | advance_repayment
            $table->date('entry_date');
            $table->decimal('amount', 15, 2);                  // موجبٌ دائمًا — الاتجاه من `kind`
            $table->foreignId('financial_voucher_id')->nullable()->constrained('financial_vouchers')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_profile_id', 'kind']);
        });

        if (! Schema::hasTable('accounts')) {
            return;
        }

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode]) {
            // بلا أبٍ لا يُنشأ الحساب: حسابٌ يتيمٌ في جذر الشجرة أسوأ من تركه.
            $parent = Account::where('code', $parentCode)->first();
            if (! $parent) {
                continue;
            }

            Account::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'parent_id' => $parent->id, 'is_postable' => true],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_finance_entries');

        // الحسابات لا تُحذف: قد تكون حملت قيودًا، وحذفُ حسابٍ مُرحَّلٍ عليه
        // يترك قيدًا بلا حساب.
    }
};
