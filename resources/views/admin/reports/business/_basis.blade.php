{{--
    أساس احتساب التقرير.

    ثلاثة تقارير مبيعات تعطي ثلاثة أرقام مختلفة لليوم نفسه — كلٌّ صحيحٌ لسؤاله،
    لكن الشاشة كانت تعرض الرقم عاريًا فيبدو الاختلاف خللًا. هذا البيان يقول
    صراحةً ما يدخل في الرقم وما لا يدخل، فيُقرأ الفرق بدل أن يُشكَّ فيه.

    يُطبَع مع التقرير عمدًا (بلا `report-no-print`): الورقة المطبوعة تحتاج
    التفسير أكثر من الشاشة.

    المتغيّرات: basisIncludes[] · basisExcludes[] · basisNote (اختياري).
--}}
<div class="mt-5 rounded-xl border border-gray-200 bg-gray-50/70 p-4">
    <h4 class="flex items-center gap-2 text-sm font-semibold text-gray-800 mb-3">
        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.852l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
        </svg>
        {{ __('أساس احتساب هذا التقرير') }}
    </h4>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
        <ul class="space-y-1.5">
            @foreach ($basisIncludes as $line)
                <li class="flex items-start gap-2 text-gray-700">
                    <span class="text-emerald-600 font-bold shrink-0 leading-5">✓</span>
                    <span>{{ $line }}</span>
                </li>
            @endforeach
        </ul>
        <ul class="space-y-1.5">
            @foreach ($basisExcludes as $line)
                <li class="flex items-start gap-2 text-gray-500">
                    <span class="text-rose-500 font-bold shrink-0 leading-5">✗</span>
                    <span>{{ $line }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    @isset($basisNote)
        <p class="mt-3 pt-3 border-t border-gray-200 text-xs text-gray-500">{{ $basisNote }}</p>
    @endisset
</div>
