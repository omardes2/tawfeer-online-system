<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جهات الاتصال التسويقية — قائمةُ أرقامٍ لا سجلُّ عملاء.
 *
 * **ولماذا جدولٌ مستقلّ؟** لأن `CustomerService::create()` يُنشئ **حسابًا في
 * دليل الحسابات لكل عميل**. واستيراد خمسة عشر ألف رقمٍ كعملاء كان سيُنشئ خمسة
 * عشر ألف حسابٍ محاسبي لأشخاصٍ لم يشتروا شيئًا — يُغرق دليل الحسابات وكشوف
 * الذمم وكل شاشةٍ تعرض حسابات العملاء.
 *
 * والفرق ليس تقنيًّا بل في المعنى: **العميل من عاملك فعلًا** وله ذمّة، و**جهة
 * الاتصال رقمٌ في قائمة**. وأكثر من في هذه القائمة راسلوا ولم يشتروا.
 *
 * ومن اشترى منهم يُربَط بعميله عبر `customer_id`، فيبقى للتسويق مدخلٌ واحد
 * ولا يُكرَّر الشخص.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();

            /*
            | الرقم بصيغةٍ موحّدة (أرقام فقط بمفتاح الدولة) — وهو هويّة الصفّ.
            | الفريد عليه لا على الخام: الرقم نفسه يَرِد «0599…» و«+970599…»
            | و«00970 599» في ملفٍ واحد، ولولا التوحيد لدخل ثلاث مرّات فتصل
            | الرسالة ثلاثًا إلى شخصٍ واحد — وهو أسرع طريقٍ إلى الحجب.
            */
            $table->string('phone', 32)->unique();
            // الخام كما ورد — لتتبّع صفٍّ أُسقط أو طُبّع خطأً.
            $table->string('phone_raw', 64)->nullable();

            $table->string('name', 160)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // من أين جاء الرقم ومتى — عليه وحده يقوم قرار «هل يجوز مراسلته».
            $table->string('source', 40)->default('import');
            $table->string('source_ref', 120)->nullable();

            /*
            | حالة الموافقة. الافتراض `unknown` لا `opted_in` — وهذا مقصود:
            | الاستيراد لا يُنشئ موافقةً، وادّعاؤها في العمود يجعل النظام يكذب
            | على نفسه. و`implied` لمن اشترى أو راسل، و`explicit` لمن وافق صراحةً.
            */
            $table->string('consent_state', 20)->default('unknown');
            // أساس الموافقة كما أقرّه من استورد — إقرارُ تاجرٍ لا موافقةُ زبون.
            $table->string('consent_basis', 60)->nullable();
            $table->timestamp('consent_at')->nullable();

            $table->timestamp('last_contacted_at')->nullable();
            /*
            | لحظة إبلاغ المنصّة أن هذا الرقم حجبنا. أهمّ عمودٍ في الجدول: نسبة
            | الحجب هي ما يُسقط درجة جودة الرقم ثم يُحظره، ورقمٌ حجبنا مرّة لا
            | يُراسَل ثانيةً أبدًا.
            */
            $table->timestamp('blocked_at')->nullable();

            // بقيّة أعمدة الملف كما وردت — مدينة، ملاحظة، تاريخ.
            $table->json('extra')->nullable();

            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consent_state', 'blocked_at'], 'marketing_contacts_sendable');
            $table->index('customer_id', 'marketing_contacts_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contacts');
    }
};
