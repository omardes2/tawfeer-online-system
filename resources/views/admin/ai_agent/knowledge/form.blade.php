<x-app-layout :title="__('المعرفة البيعية')">
    <x-admin.header
        :title="$product->name"
        :description="__('ما يقوله الوكيل عن هذا الصنف — يُقال للزبائن حرفيًّا.')"
        :breadcrumbs="[
            __('الرئيسية') => route('admin.dashboard'),
            __('المعرفة البيعية') => route('admin.ai_agent.knowledge.index'),
            $product->name => null,
        ]" />

    <x-admin.flash />

    <form method="POST" action="{{ route('admin.ai_agent.knowledge.update', $product) }}"
          x-data="knowledgeForm(@js([
              'selling_points' => old('selling_points', $knowledge->selling_points ?: ['']),
              'use_cases' => old('use_cases', $knowledge->use_cases ?: ['']),
              'objections' => old('objections', $knowledge->objections ?: [['objection' => '', 'response' => '']]),
              'faq' => old('faq', $knowledge->faq ?: [['question' => '', 'answer' => '']]),
          ]))"
          class="space-y-5 max-w-4xl">
        @csrf
        @method('PUT')

        {{-- نقاط البيع --}}
        <div class="admin-card p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('نقاط البيع') }}</h2>
            <p class="mt-1 mb-3 text-xs text-gray-500">
                {{ __('لماذا يشتريه الزبون؟ اكتبها بلغته لا بلغة الكتالوج — «بيوفّر أجرة عاملة بشهر» لا «كفاءة تشغيلية عالية».') }}
            </p>

            <template x-for="(point, i) in selling_points" :key="'sp'+i">
                <div class="flex gap-2 mb-2">
                    <input type="text" :name="'selling_points['+i+']'" x-model="selling_points[i]" maxlength="300"
                           class="flex-1 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <button type="button" @click="selling_points.splice(i,1)" x-show="selling_points.length > 1"
                            class="px-2 text-rose-500 hover:text-rose-700" :aria-label="'حذف'">&times;</button>
                </div>
            </template>
            <button type="button" @click="selling_points.push('')" class="btn-secondary btn-sm">{{ __('+ نقطة') }}</button>
        </div>

        {{-- الاستخدامات --}}
        <div class="admin-card p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('الاستخدامات') }}</h2>
            <p class="mt-1 mb-3 text-xs text-gray-500">{{ __('متى وأين يُستعمل؟ بها يعرف الوكيل هل يناسب الزبونَ الذي أمامه.') }}</p>

            <template x-for="(useCase, i) in use_cases" :key="'uc'+i">
                <div class="flex gap-2 mb-2">
                    <input type="text" :name="'use_cases['+i+']'" x-model="use_cases[i]" maxlength="300"
                           class="flex-1 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <button type="button" @click="use_cases.splice(i,1)" x-show="use_cases.length > 1"
                            class="px-2 text-rose-500 hover:text-rose-700">&times;</button>
                </div>
            </template>
            <button type="button" @click="use_cases.push('')" class="btn-secondary btn-sm">{{ __('+ استخدام') }}</button>
        </div>

        {{-- الاعتراضات --}}
        <div class="admin-card p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('الاعتراضات وردودها') }}</h2>
            <p class="mt-1 mb-3 text-xs text-gray-500">
                {{ __('ما يقوله الزبون ليمتنع — «غالي»، «في أرخص» — وبمَ تردّ عليه. اعتراضٌ بلا ردّ لا يُحفظ: يعرفه الوكيل ولا يملك جوابًا.') }}
            </p>

            <template x-for="(row, i) in objections" :key="'ob'+i">
                <div class="grid gap-2 md:grid-cols-[1fr_1.6fr_auto] mb-2">
                    <input type="text" :name="'objections['+i+'][objection]'" x-model="row.objection" maxlength="300"
                           :placeholder="'الاعتراض'"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <input type="text" :name="'objections['+i+'][response]'" x-model="row.response" maxlength="600"
                           :placeholder="'الردّ'"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <button type="button" @click="objections.splice(i,1)" x-show="objections.length > 1"
                            class="px-2 text-rose-500 hover:text-rose-700">&times;</button>
                </div>
            </template>
            <button type="button" @click="objections.push({objection:'',response:''})" class="btn-secondary btn-sm">{{ __('+ اعتراض') }}</button>
        </div>

        {{-- الأسئلة الشائعة --}}
        <div class="admin-card p-5">
            <h2 class="text-sm font-semibold text-gray-800">{{ __('الأسئلة الشائعة') }}</h2>
            <p class="mt-1 mb-3 text-xs text-gray-500">{{ __('ما يتكرّر سؤاله عن هذا الصنف وجوابه الصحيح.') }}</p>

            <template x-for="(row, i) in faq" :key="'fq'+i">
                <div class="grid gap-2 md:grid-cols-[1fr_1.6fr_auto] mb-2">
                    <input type="text" :name="'faq['+i+'][question]'" x-model="row.question" maxlength="300"
                           :placeholder="'السؤال'"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <input type="text" :name="'faq['+i+'][answer]'" x-model="row.answer" maxlength="600"
                           :placeholder="'الجواب'"
                           class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <button type="button" @click="faq.splice(i,1)" x-show="faq.length > 1"
                            class="px-2 text-rose-500 hover:text-rose-700">&times;</button>
                </div>
            </template>
            <button type="button" @click="faq.push({question:'',answer:''})" class="btn-secondary btn-sm">{{ __('+ سؤال') }}</button>
        </div>

        {{-- النبرة والجاهزية --}}
        <div class="admin-card p-5 space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-800 mb-1">{{ __('ملاحظات على النبرة') }}</label>
                <p class="mb-2 text-xs text-gray-500">{{ __('ما يجب تجنّبه أو التشديد عليه عند الحديث عن هذا الصنف تحديدًا.') }}</p>
                <textarea name="tone_notes" rows="3" maxlength="1000"
                          class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('tone_notes', $knowledge->tone_notes) }}</textarea>
            </div>

            {{--
                الجاهزية إذنٌ بالبيع لا علامةُ اكتمال: بها يخرج الصنف في بحث
                الوكيل ويتكلّم عنه. ولذلك تُشرح هنا لا تُترك مربّعًا صامتًا.
            --}}
            <label class="flex items-start gap-3 rounded-lg bg-emerald-50 ring-1 ring-emerald-200 p-3">
                <input type="hidden" name="is_ready" value="0" />
                <input type="checkbox" name="is_ready" value="1" @checked(old('is_ready', $knowledge->is_ready))
                       class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                <span class="text-sm">
                    <span class="font-semibold text-emerald-900">{{ __('جاهز — اسمح للوكيل ببيع هذا الصنف') }}</span>
                    <span class="block mt-1 text-xs text-emerald-800">
                        {{ __('بلا هذه العلامة لا يظهر الصنف في بحث الوكيل، ويُحوَّل السؤال عنه إلى موظفة.') }}
                    </span>
                </span>
            </label>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
            <a href="{{ route('admin.ai_agent.knowledge.index') }}" class="btn-secondary">{{ __('رجوع') }}</a>
        </div>
    </form>

    @push('scripts')
        <script>
            function knowledgeForm(initial) {
                return {
                    selling_points: initial.selling_points.length ? initial.selling_points : [''],
                    use_cases: initial.use_cases.length ? initial.use_cases : [''],
                    objections: initial.objections.length ? initial.objections : [{objection:'',response:''}],
                    faq: initial.faq.length ? initial.faq : [{question:'',answer:''}],
                };
            }
        </script>
    @endpush
</x-app-layout>
