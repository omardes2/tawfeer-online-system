{{--
    شريط فلاتر موحّد للتقارير — اختصارات التاريخ + نطاق مخصّص + تصدير/طباعة.

    Props:
      range    كائن DateRange الحالي.
      action   مسار التقرير (route الحالي) لإرسال الفلتر عبر GET.
      exports  إظهار أزرار التصدير (CSV/طباعة). افتراضي true.
    Slot افتراضي: فلاتر إضافية (قوائم منسدلة للموظف/المدينة/الفئة...).
--}}
@props(['range', 'action' => null, 'exports' => true])

@php
    use App\Modules\Reporting\Support\DateRange;
    $action = $action ?: url()->current();
    $presets = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year'];
    $current = $range->preset;
    // نحافظ على باقي مُعاملات الاستعلام (الفلاتر الإضافية) عند تبديل الاختصار.
    $carry = collect(request()->except(['preset', 'from', 'to', 'page', 'export']));
@endphp

<div class="admin-card p-4 mb-6 print:hidden" x-data="{ custom: @js($current === 'custom') }">
    <form method="GET" action="{{ $action }}" class="space-y-3">
        {{-- حمل الفلاتر الإضافية القائمة --}}
        @foreach ($carry as $k => $v)
            @if (!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}" />@endif
        @endforeach

        <div class="flex flex-wrap items-center gap-2">
            @foreach ($presets as $p)
                <a href="{{ $action }}?{{ http_build_query(array_merge($carry->all(), ['preset' => $p])) }}"
                   @class([
                       'px-3 py-1.5 rounded-lg text-xs font-medium transition border',
                       'bg-emerald-600 text-white border-emerald-600' => $current === $p,
                       'bg-white text-gray-600 border-gray-200 hover:border-emerald-400 hover:text-emerald-700' => $current !== $p,
                   ])>{{ DateRange::LABELS[$p] }}</a>
            @endforeach
            <button type="button" @click="custom = !custom"
                    @class([
                        'px-3 py-1.5 rounded-lg text-xs font-medium transition border',
                        'bg-emerald-600 text-white border-emerald-600' => $current === 'custom',
                        'bg-white text-gray-600 border-gray-200 hover:border-emerald-400 hover:text-emerald-700' => $current !== 'custom',
                    ])>{{ DateRange::LABELS['custom'] }}</button>

            <span class="text-xs text-gray-400 ms-auto">{{ $range->fromString() }} — {{ $range->toString() }}</span>
        </div>

        {{-- النطاق المخصّص --}}
        <div x-show="custom" x-cloak class="flex flex-wrap items-end gap-3 pt-1">
            <input type="hidden" name="preset" value="custom" />
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('من') }}</label>
                <input type="date" name="from" value="{{ $range->fromString() }}" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('إلى') }}</label>
                <input type="date" name="to" value="{{ $range->toString() }}" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>
            <button type="submit" class="btn-primary btn-sm">{{ __('تطبيق') }}</button>
        </div>

        {{-- فلاتر إضافية (اختياري) --}}
        @if (trim($slot) !== '')
            <div class="flex flex-wrap items-end gap-3 pt-1 border-t border-gray-100 mt-2">
                {{ $slot }}
                <button type="submit" class="btn-secondary btn-sm">{{ __('فلترة') }}</button>
            </div>
        @endif
    </form>

    @if ($exports)
        <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-400">{{ __('تصدير:') }}</span>
            <a href="{{ $action }}?{{ http_build_query(array_merge(request()->except('export'), ['export' => 'csv'])) }}"
               class="btn-secondary btn-sm">CSV / Excel</a>
            <button type="button" onclick="window.print()" class="btn-secondary btn-sm">{{ __('طباعة / PDF') }}</button>
        </div>
    @endif
</div>
