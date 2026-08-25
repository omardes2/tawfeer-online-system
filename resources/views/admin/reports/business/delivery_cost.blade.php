<x-app-layout :title="__('تكلفة التوصيل')">
    <div class="report-no-print">
        <x-admin.header
            :title="__('تكلفة التوصيل')"
            :description="__('ما دُفع لشركة التوصيل حسب المدينة والمنطقة — للمطابقة مع كشفها.')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('تكلفة التوصيل') => null]" />

        <x-admin.flash />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('تكلفة التوصيل')])

    {{--
        مرشّح المدينة والمنطقة. المنطقة تابعةٌ للمدينة: تُعرض مناطق المختارة
        وحدها، فقائمةُ كل مناطق البلاد غير قابلة للاستعمال، ومنطقةٌ من مدينةٍ
        أخرى تُنتج تقريرًا فارغًا بلا سبب ظاهر.
    --}}
    <form method="GET" class="admin-card admin-card-pad mb-5 flex flex-wrap items-end gap-3 report-no-print">
        <input type="hidden" name="from" value="{{ $range->from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $range->to->toDateString() }}">

        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('المدينة') }}</label>
            <select name="city_id" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm min-w-[12rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('كل المدن') }}</option>
                @foreach ($cities as $city)
                    <option value="{{ $city->id }}" @selected($cityId === $city->id)>{{ $city->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('المنطقة') }}</label>
            <select name="area_id" @disabled($cities->isNotEmpty() && ! $cityId)
                    class="rounded-lg border-gray-300 text-sm min-w-[12rem] disabled:bg-gray-100 focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('كل المناطق') }}</option>
                @foreach ($areas as $area)
                    <option value="{{ $area->id }}" @selected($areaId === $area->id)>{{ $area->name }}</option>
                @endforeach
            </select>
            @unless ($cityId)
                <p class="mt-1 text-[11px] text-gray-400">{{ __('اختر مدينةً أولًا') }}</p>
            @endunless
        </div>

        <button type="submit" class="btn-secondary btn-sm">{{ __('تطبيق') }}</button>
    </form>

    {{-- الملخّص: أربعة أرقام تُطابَق بها فاتورة الشركة --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-5">
        <x-admin.stat-card :label="__('إجمالي المدفوع')" :value="$totals['cost']" money tone="amber"
                           :hint="__('لشركة التوصيل — بلا مبيعات')" />
        <x-admin.stat-card :label="__('عدد الطرود')" :value="$totals['parcels']" tone="blue" />
        <x-admin.stat-card :label="__('متوسط الطرد')" :value="$totals['avg']" money tone="gray" />
        {{--
            طرودٌ بلا رسمٍ مكتوب عندنا. ستأتي في فاتورة الشركة، فوجودها يفسّر
            فرقًا بين مجموعنا ومجموعها قبل أن يُبحث عنه.
        --}}
        <x-admin.stat-card :label="__('طرود بلا رسم')" :value="$totals['unpriced']"
                           :tone="$totals['unpriced'] > 0 ? 'red' : 'green'"
                           :hint="__('لم تُكتب لها تكلفة — راجعها قبل المطابقة')" />
    </div>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('المدينة') }}</th>
                        <th>{{ __('المنطقة') }}</th>
                        <th class="text-start">{{ __('الطرود') }}</th>
                        <th class="text-start">{{ __('التكلفة') }}</th>
                        <th class="text-start">{{ __('متوسط الطرد') }}</th>
                        <th class="text-start">{{ __('بلا رسم') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td data-label="{{ __('المدينة') }}" class="font-medium text-gray-800">{{ $r['city'] }}</td>
                            <td data-label="{{ __('المنطقة') }}" class="text-gray-600">{{ $r['area'] }}</td>
                            <td data-label="{{ __('الطرود') }}" class="text-start tabular-nums">{{ number_format($r['parcels']) }}</td>
                            <td data-label="{{ __('التكلفة') }}" class="text-start tabular-nums font-medium text-amber-700">{{ number_format($r['cost'], 2) }}</td>
                            <td data-label="{{ __('متوسط الطرد') }}" class="text-start tabular-nums text-gray-500">{{ number_format($r['avg'], 2) }}</td>
                            <td data-label="{{ __('بلا رسم') }}" class="text-start tabular-nums {{ $r['unpriced'] > 0 ? 'text-rose-600 font-medium' : 'text-gray-300' }}">{{ $r['unpriced'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty-state
                                    :title="__('لا طرود في هذه الفترة')"
                                    :description="__('غيّر النطاق الزمني أو المدينة.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="border-t-2 border-gray-300 font-bold">
                        <tr>
                            <td colspan="2">{{ __('الإجمالي') }}</td>
                            <td class="text-start tabular-nums">{{ number_format($totals['parcels']) }}</td>
                            <td class="text-start tabular-nums text-amber-800">{{ number_format($totals['cost'], 2) }}</td>
                            <td class="text-start tabular-nums">{{ number_format($totals['avg'], 2) }}</td>
                            <td class="text-start tabular-nums">{{ $totals['unpriced'] ?: '—' }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500 leading-relaxed">
        {{ __('التكلفة كما كُتبت على الطرد وقت إنشائه، والتاريخ تاريخ الإنشاء لا التسليم — الشركة تُحاسب على ما أُرسل في الشهر. والطرود المرتجعة داخلة: أجرة الإرجاع تُدفع أيضًا.') }}
    </p>
</x-app-layout>
