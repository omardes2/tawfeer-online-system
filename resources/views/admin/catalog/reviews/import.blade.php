<x-app-layout :title="__('استيراد تقييمات')">
    <x-admin.header
        :title="__('استيراد تقييمات الزبائن')"
        :description="__('نقل آراءٍ قالها زبائنك فعلًا خارج المتجر (واتساب وغيره) إلى صفحات منتجاتها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('تقييمات الزبائن') => route('admin.reviews.index'), __('استيراد') => null]" />

    <div class="mb-5">
        <x-admin.alert tone="amber" :title="__('آراء حقيقية فقط')">
            {{ __('هذه الأداة لنقل رأيٍ قاله زبونٌ فعلًا، لا لإنشاء آراء. كل سطر يُربَط بصنفه المحدَّد وبصاحبه برقم هاتفه — ولا يُوزَّع رأيٌ على صنفٍ لم يُقَل فيه.') }}
            <span class="block mt-1 text-xs">{{ __('وما لا يُطابِق صنفًا في الكتالوج يُرفَض ويُعرَض سببه، ولا يُخمَّن.') }}</span>
        </x-admin.alert>
    </div>

    <div class="admin-card p-5 mb-5">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h3 class="font-semibold text-gray-800">{{ __('رفع الملف') }}</h3>
            <a href="{{ route('admin.reviews.import.template') }}" class="btn-secondary btn-sm">{{ __('تنزيل ملف نموذجي') }}</a>
        </div>

        @isset($fileError)
            <div class="mb-4"><x-admin.alert tone="red" :title="__('تعذّر قراءة الملف')">{{ $fileError }}</x-admin.alert></div>
        @endisset

        <form method="POST" action="{{ route('admin.reviews.import.upload') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('ملف CSV بترميز UTF-8') }}</label>
                    <input type="file" name="file" accept=".csv,text/csv" required
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('من Excel: حفظ باسم ← CSV UTF-8 (محدّد بفواصل).') }}</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('مصدر الآراء') }}</label>
                    <input type="text" name="source" value="{{ old('source', __('واتساب')) }}" maxlength="60"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('يُسجَّل مع كل رأي فيبقى أثرٌ صادق لكيفية وصوله.') }}</p>
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="approve" value="1" class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                <span>
                    <span class="text-gray-800">{{ __('اعتمدها ونشرها مباشرة') }}</span>
                    <span class="block text-xs text-gray-500">{{ __('بلا هذا الخيار تصل معلّقة وتراجعها في شاشة التقييمات — الأأمن مع ملفٍ كبير لم تراجعه.') }}</span>
                </span>
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="submit" name="preview" value="1" class="btn-secondary">{{ __('معاينة بلا حفظ') }}</button>
                <button type="submit" class="btn-primary">{{ __('استيراد') }}</button>
            </div>
        </form>
    </div>

    @isset($result)
        @if ($result)
            @php $rows = collect($result['rows']); @endphp

            <div class="grid gap-4 sm:grid-cols-3 mb-5">
                <x-admin.stat-card :label="__('صفوف صالحة')" :value="number_format($rows->count())" tone="green" />
                <x-admin.stat-card :label="__('صفوف مرفوضة')" :value="number_format(count($result['errors']))"
                                   :tone="count($result['errors']) > 0 ? 'amber' : 'gray'" />
                <x-admin.stat-card :label="__('زبائن غير مسجّلين')" :value="number_format($rows->where('known_customer', false)->count())" tone="blue"
                                   :hint="__('سيُنشأون عند الاستيراد')" />
            </div>

            @if ($result['imported'])
                <div class="mb-5">
                    <x-admin.alert tone="green" :title="__('اكتمل الاستيراد')">
                        {{ __('استُورد :i رأيًا · أُنشئ :c زبونًا · رُبط :o منها بطلبٍ فعليّ في النظام.', [
                            'i' => $result['imported']['imported'],
                            'c' => $result['imported']['customers_created'],
                            'o' => $result['imported']['linked_to_orders'],
                        ]) }}
                        <a href="{{ route('admin.reviews.index') }}" class="font-semibold underline">{{ __('افتح شاشة التقييمات') }}</a>
                    </x-admin.alert>
                </div>
            @elseif ($result['preview'])
                <div class="mb-5">
                    <x-admin.alert tone="blue" :title="__('معاينة — لم يُحفظ شيء')">
                        {{ __('راجع الصفوف أدناه، ثم ارفع الملف نفسه واضغط «استيراد».') }}
                    </x-admin.alert>
                </div>
            @endif

            @if ($result['errors'] !== [])
                <div class="admin-card p-4 mb-5">
                    <h3 class="font-semibold text-gray-800 mb-2">{{ __('صفوف لم تُقبل') }}</h3>
                    <ul class="space-y-1 text-sm text-rose-700 max-h-64 overflow-y-auto">
                        @foreach ($result['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($rows->isNotEmpty())
                <x-admin.table dense>
                    <thead>
                        <tr>
                            <th>{{ __('السطر') }}</th>
                            <th>{{ __('الصنف') }}</th>
                            <th>{{ __('الهاتف') }}</th>
                            <th>{{ __('الزبون') }}</th>
                            <th class="text-start">{{ __('التقييم') }}</th>
                            <th>{{ __('الرأي') }}</th>
                            <th>{{ __('التاريخ') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="text-gray-400 tabular-nums">{{ $row['line'] }}</td>
                                <td class="font-medium text-gray-800">{{ $row['product'] }}</td>
                                <td class="tabular-nums text-gray-500" dir="ltr">{{ $row['phone'] }}</td>
                                <td class="text-gray-600">
                                    {{ $row['name'] ?: '—' }}
                                    @unless ($row['known_customer'])
                                        <span class="ms-1 rounded px-1.5 py-0.5 text-[10px] bg-sky-50 text-sky-700">{{ __('جديد') }}</span>
                                    @endunless
                                </td>
                                <td class="text-start tabular-nums">{{ $row['rating'] }}/5</td>
                                <td class="text-gray-600 max-w-[24rem] truncate" title="{{ $row['body'] }}">{{ $row['body'] ?: '—' }}</td>
                                <td class="text-gray-500 text-xs">{{ $row['date']?->toDateString() ?: __('تاريخ اليوم') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-admin.table>
            @endif
        @endif
    @endisset

    <div class="admin-card p-4 mt-5 text-xs text-gray-500 leading-relaxed">
        <p class="font-semibold text-gray-700 mb-1">{{ __('أعمدة الملف') }}</p>
        <p>{{ __('إلزامية: «الصنف» (الرمز أو الاسم حرفيًّا) · «الهاتف» · «التقييم» (1 إلى 5). واختيارية: «الاسم» · «الرأي» · «العنوان» · «التاريخ».') }}</p>
        <p class="mt-1">{{ __('استعمل رمز الصنف (SKU) لا اسمه: الرمز ثابت، والاسم قد تعدّله يومًا فيفشل المطابقة.') }}</p>
        <p class="mt-1">{{ __('وضع تواريخ الآراء الحقيقية مهمّ: مئة رأي بتاريخ اليوم تظهر دفعةً واحدة فتبدو مفتعلة وإن كانت صادقة.') }}</p>
        <p class="mt-1">{{ __('الزبون يُطابَق برقم هاتفه بكل صيغه (المحلية والدولية)، ومن لم يكن مسجّلًا يُنشأ. ورأيٌ واحد لكل زبون على كل صنف — المكرّر يُتخطّى ويُعرَض سببه.') }}</p>
    </div>
</x-app-layout>
