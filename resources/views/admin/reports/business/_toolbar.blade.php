@php use App\Modules\Reporting\Support\DateRange; @endphp

{{-- خيارات التقرير: نطاق زمني (من/إلى + اختصارات) + تصدير/طباعة. مخفيّة عند الطباعة. --}}
<div class="admin-card p-4 mb-5 report-no-print" x-data="{ preset: '{{ $range->preset }}' }">
    <h3 class="flex items-center gap-2 font-semibold text-gray-800 mb-3">
        <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085"/></svg>
        {{ __('خيارات التقرير') }}
    </h3>
    <form method="GET" action="{{ url()->current() }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('النطاق الزمني') }}</label>
            <select name="range" x-model="preset" @change="preset !== 'custom' && $el.form.submit()"
                    class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 min-w-[9rem]">
                @foreach (DateRange::LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($range->preset === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('من تاريخ') }}</label>
            <input type="date" name="from" value="{{ $range->fromString() }}" @change="preset = 'custom'"
                   class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('إلى تاريخ') }}</label>
            <input type="date" name="to" value="{{ $range->toString() }}" @change="preset = 'custom'"
                   class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        {{-- فلتر الأشخاص (اختياري): يظهر في التقارير التي تمرّر $people. تحديد متعدّد
             بمربّعات اختيار داخل قائمة منسدلة — أوضح من select متعدّد يحتاج Ctrl. --}}
        @isset($people)
            <div x-data="{ open: false, count: {{ count($selectedPeople ?? []) }} }" class="relative">
                <label class="block text-xs text-gray-500 mb-1">{{ $peopleLabel ?? __('الأشخاص') }}</label>
                <button type="button" x-on:click="open = ! open"
                        class="rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 min-w-[11rem] text-start flex items-center justify-between gap-2">
                    <span x-text="count === 0 ? '{{ __('الكل') }}' : count + ' {{ __('محدَّد') }}'"></span>
                    <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     class="absolute z-30 mt-1 w-64 max-h-64 overflow-y-auto rounded-lg bg-white shadow-lg ring-1 ring-black/5 p-2">
                    @forelse ($people as $p)
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-gray-50 text-sm cursor-pointer">
                            <input type="checkbox" name="users[]" value="{{ $p->id }}"
                                   @checked(in_array($p->id, $selectedPeople ?? [], true))
                                   x-on:change="count += $el.checked ? 1 : -1"
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-gray-700">{{ $p->name }}</span>
                        </label>
                    @empty
                        <p class="px-2 py-3 text-center text-sm text-gray-400">{{ __('لا يوجد أحد بعد.') }}</p>
                    @endforelse
                </div>
            </div>
        @endisset

        <button type="submit" class="btn-primary btn-sm">{{ __('إنشاء التقرير') }}</button>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary btn-sm">{{ __('تصدير Excel') }}</a>
        <button type="button" onclick="window.print()" class="btn-secondary btn-sm">{{ __('طباعة / PDF') }}</button>
    </form>
</div>

{{-- ترويسة المعاينة: اسم الشركة + عنوان التقرير + الفترة. --}}
<div class="text-center mb-4">
    <h2 class="text-lg font-bold text-gray-800">{{ $company }}</h2>
    <p class="text-gray-600">{{ $title }}</p>
    <p class="text-xs text-gray-400 mt-1">
        @if ($asOf ?? false)
            {{ __('كما في تاريخ :d', ['d' => $range->toString()]) }}
        @else
            {{ __('من تاريخ :from إلى :to', ['from' => $range->fromString(), 'to' => $range->toString()]) }}
        @endif
    </p>
</div>

{{-- إخفاء عناصر الواجهة عند الطباعة (تصدير PDF عبر المتصفّح) --}}
<style>@media print { aside, .report-no-print, .admin-topbar { display: none !important; } .admin-card { box-shadow: none !important; } }</style>
