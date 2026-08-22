<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\AiAgent\Services\HandoffService;
use App\Modules\Messaging\Models\Conversation;

/**
 * تسليم المحادثة إلى موظفة — الأداة التي **تُسكت الوكيل**.
 *
 * وهي أهمّ أدواته: وكيلٌ لا يعرف متى يصمت أخطرُ من وكيلٍ لا يعرف الجواب. فحين
 * يعجز، أو يغضب الزبون، أو يُسأل عن مبلغٍ مدفوع — الصمتُ والتحويل أنفع من
 * محاولةٍ تُفسد علاقةً.
 *
 * ولا يعود بعدها إلّا بقرارٍ صريح من إنسان: `handed_off` لا `paused`. وإعادتُه
 * تلقائيًّا إلى محادثةٍ حُوّلت لغضب الزبون تُضاعف الغضب.
 *
 * والسبب يُسجَّل بمفتاحٍ ثابت لا بجملةٍ حرّة: به تُقرأ أسبابُ التحويل مجموعةً
 * في الصندوق — «كم مرّة عجز الوكيل هذا الأسبوع؟» سؤالٌ لا يُجاب بنصٍّ حرّ.
 */
class EscalateToHumanTool implements ContextAwareTool, ToolContract
{
    /** أسبابٌ مغلقة — لا نصّ حرّ. */
    private const REASONS = [
        'complaint' => 'شكوى أو غضب',
        'return_or_cancel' => 'استرجاع أو إلغاء',
        'past_order' => 'سؤال عن طلبٍ سابق أو مبلغٍ مدفوع',
        'discount_request' => 'طلب خصمٍ أو سعر جملة',
        'out_of_scope' => 'سؤال خارج البيع',
        'cannot_answer' => 'تعذّر الجواب بالأدوات المتاحة',
        'customer_asked' => 'الزبون طلب موظفة',
    ];

    private ?Conversation $conversation = null;

    public function __construct(private readonly HandoffService $handoff) {}

    public function setConversation(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function name(): string
    {
        return 'escalate_to_human';
    }

    public function description(): string
    {
        return 'حوّل المحادثة إلى موظفة وتوقّف عن الردّ. '
            .'استعملها عند الشكوى أو الغضب أو طلب الاسترجاع أو الإلغاء، '
            .'أو السؤال عن طلبٍ سابق أو مبلغٍ مدفوع، أو طلب خصم، '
            .'أو إن عجزت عن الجواب بالأدوات المتاحة.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => array_keys(self::REASONS),
                    'description' => 'سبب التحويل.',
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'سطرٌ للموظفة يلخّص ما يريده الزبون.',
                ],
            ],
            'required' => ['reason'],
        ];
    }

    public function handle(array $arguments): array
    {
        if ($this->conversation === null) {
            return ['error' => 'no_conversation', 'message' => 'لا محادثة.'];
        }

        $reason = (string) ($arguments['reason'] ?? 'cannot_answer');

        if (! isset(self::REASONS[$reason])) {
            $reason = 'cannot_answer';
        }

        // الإبلاغ من الوكيل نفسه في ردّه الأخير، لا رسالةً ثانية من الخدمة:
        // رسالتان متتاليتان («عذرًا…» ثم «بحوّلك…») تبدوان ارتباكًا.
        $this->handoff->handoff($this->conversation, $reason, notice: null, note: $arguments['note'] ?? null);

        return [
            'handed_off' => true,
            'reason' => self::REASONS[$reason],
            'note_for_agent' => 'اكتب للزبون جملةً قصيرة تعتذر وتخبره أن موظفة ستتابع معه، ثم توقّف.',
        ];
    }
}
