<?php

namespace App\Modules\AiAgent\Tools;

/**
 * عقد أداةٍ يستدعيها الوكيل.
 *
 * كل ما يعرفه الوكيل عن المتجر يمرّ من هنا. والمبدأ الأول — «لا سعر ولا توفّر
 * إلّا نتيجةَ أداة» — يُطبَّق بأن لا يُعطى النموذجُ شيئًا غير مخرجات هذه
 * الأدوات: لا كتالوج في البرومبت، ولا أرقام محفوظة.
 *
 * والمخرجات **مصفوفة قابلة للتحويل إلى JSON**، وتُقصَّ على ما يحتاجه البيع:
 * تمريرُ أعمدة زائدة يُغري النموذج بذكر ما لا يُفترض أن يذكره (تكلفة الشراء
 * مثلًا)، ويُنفق توكنز بلا مقابل.
 */
interface ToolContract
{
    /** الاسم كما يراه النموذج — `snake_case` ثابت لا يتغيّر بعد الإطلاق. */
    public function name(): string;

    /** وصفٌ للنموذج: متى تُستدعى هذه الأداة، لا كيف تعمل. */
    public function description(): string;

    /**
     * مخطّط المدخلات بصيغة JSON Schema.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * التنفيذ.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
