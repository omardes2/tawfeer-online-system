<?php

namespace App\Http\Requests\AiAgent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * تحقّق المعرفة البيعية — ما سيقوله الوكيل للزبائن حرفيًّا.
 */
class ProductKnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai_agent.knowledge.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'selling_points' => ['nullable', 'array', 'max:10'],
            'selling_points.*' => ['nullable', 'string', 'max:300'],

            'use_cases' => ['nullable', 'array', 'max:10'],
            'use_cases.*' => ['nullable', 'string', 'max:300'],

            'objections' => ['nullable', 'array', 'max:10'],
            'objections.*.objection' => ['nullable', 'string', 'max:300'],
            'objections.*.response' => ['nullable', 'string', 'max:600'],

            'faq' => ['nullable', 'array', 'max:15'],
            'faq.*.question' => ['nullable', 'string', 'max:300'],
            'faq.*.answer' => ['nullable', 'string', 'max:600'],

            'tone_notes' => ['nullable', 'string', 'max:1000'],
            'is_ready' => ['nullable', 'boolean'],
        ];
    }

    /**
     * صنفٌ يُوسَم «جاهز» ولا نقطة بيعٍ فيه لا يُفيد الوكيل شيئًا.
     *
     * الجاهزية إذنٌ بالبيع: بها يخرج الصنف في نتائج البحث ويتكلّم عنه الوكيل.
     * فوسمُه جاهزًا وهو فارغ يُخرجه للزبائن بلا ما يُقال عنه — والوكيل مأمورٌ
     * ألّا يرتجل، فيقف صامتًا في موضعٍ ظنّ صاحبُ النظام أنه مُعدٌّ.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->boolean('is_ready')) {
                return;
            }

            $points = collect($this->input('selling_points', []))->filter(fn ($p) => filled($p));

            if ($points->isEmpty()) {
                $v->errors()->add('selling_points', __('لا تُوسَم المعرفة «جاهزة» بلا نقطة بيعٍ واحدة على الأقل.'));
            }
        });
    }
}
