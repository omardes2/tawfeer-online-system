<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ قرارات الطيّار الآلي — كل ما فعله ولماذا، وما امتنع عنه ولماذا.
 *
 * الجدول ليس أثرًا جانبيًّا للأتمتة بل شرطُها. نظامٌ يصرف مالًا وحده بلا سجلٍّ
 * صندوقٌ أسود: لا يُراجَع قبل رفع مستوى صلاحياته، ولا يُتراجَع عن قرارٍ منه،
 * ولا يُعرَف أخطأ أم أصاب. ولذلك تُسجَّل **الأرقام التي بُني عليها القرار** لا
 * القرار وحده — مراجعتُه بعد شهر تحتاج ما كان يراه يومئذٍ لا ما تراه اليوم.
 *
 * ويُسجَّل الامتناع كما يُسجَّل الفعل: «لم أُخفّض لأن الميزانية على مستوى الحملة»
 * معلومةٌ يحتاجها صاحب العمل، وغيابُها يجعل الصمت يبدو رضًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_autopilot_decisions', function (Blueprint $table) {
            $table->id();

            // يوم التشغيل، ويوم البيانات التي بُني عليها القرار — وهما مختلفان
            // دائمًا: الطيّار يعمل صباح اليوم على أرقام أمس.
            $table->date('decided_on');
            $table->date('report_day');

            $table->foreignId('ad_channel_id')->nullable()->constrained('ad_channels')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // المجموعة الإعلانية على المنصّة — بالمعرّف لا بالاسم.
            $table->string('external_id', 64);
            $table->string('external_name', 255)->nullable();

            // pause | decrease | resume | increase | skip
            $table->string('action', 20);
            // حكم `AdBudgetService` الذي أنتج القرار: stop | reduce | ...
            $table->string('verdict', 20)->nullable();
            $table->text('reason');

            $table->decimal('budget_before', 15, 2)->nullable();
            $table->decimal('budget_after', 15, 2)->nullable();
            $table->string('currency', 8)->nullable();

            // لقطة أرقام النافذة — دليل القرار محفوظًا معه.
            $table->decimal('window_spend', 15, 2)->default(0);
            $table->unsignedInteger('window_orders')->default(0);
            $table->decimal('window_cpa', 15, 2)->nullable();
            $table->decimal('window_net_profit', 15, 2)->default(0);

            // planned | applied | skipped | failed | reverted
            $table->string('status', 20)->default('planned');
            $table->text('error')->nullable();

            // auto: الدورة المجدولة · manual: تدخّلٌ من الشاشة (إيقاف طارئ مثلًا)
            $table->string('source', 10)->default('auto');
            // الوضع الذي أنتجه: suggest (اقتراح) | brake (فرملة آلية)
            $table->string('mode', 10)->default('suggest');

            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
            | قرارٌ واحد لكل (يوم، مجموعة، مصدر): تشغيل الأمر مرّتين في اليوم نفسه
            | يُحدِّث الصفّ ولا يُنشئ ثانيًا — فلا يُخفَّض الصرف مرّتين بحكمٍ واحد.
            | وهذا الحارس في القاعدة لا في الخدمة وحدها لأن الأمر قد يُشغَّل يدويًّا
            | بينما الجدولة تعمل.
            */
            $table->unique(['decided_on', 'external_id', 'source'], 'ad_autopilot_decisions_daily');
            $table->index(['report_day', 'ad_channel_id'], 'ad_autopilot_decisions_report');
            $table->index(['status', 'decided_on'], 'ad_autopilot_decisions_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_autopilot_decisions');
    }
};
