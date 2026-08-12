<x-app-layout :title="__('استيراد أصناف')">
    @php($sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <x-admin.header :title="__('استيراد أصناف (رصيد افتتاحي)')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), __('استيراد أصناف') => null]" />

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-5">
        <x-admin.flash />

        @if ($fileError ?? false)
            <div class="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $fileError }}</div>
        @endif

        {{-- الرفع --}}
        <div class="admin-card p-5">
            <h3 class="font-semibold text-gray-800 mb-1">{{ __('رفع الملف') }}</h3>
            <p class="text-xs text-gray-500 mb-4">
                {{ __('من Excel: ملف ← حفظ باسم ← اختر النوع «CSV UTF-8 (محدَّد بفواصل)». الأعمدة المطلوبة: اسم الصنف، الكمية — والبقية اختيارية: سعر البيع، سعر الجملة، سعر الشراء، الفئات.') }}
            </p>

            <form method="POST" action="{{ route('admin.products.import.upload') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv" required
                       class="block w-full text-sm text-gray-700 file:me-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100" />
                @error('file')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" name="preview" value="1" class="btn-secondary btn-sm">{{ __('معاينة بدون حفظ') }}</button>
                    <button type="submit" class="btn-primary btn-sm"
                            onclick="return confirm('{{ __('تنفيذ الاستيراد؟ ستُنشأ الأصناف وتُدخل كمياتها ويُرحَّل قيد الرصيد الافتتاحي.') }}')">
                        {{ __('استيراد') }}
                    </button>
                    <span class="text-xs text-gray-400">
                        {{ __('المستودع: :w', ['w' => $warehouse?->name ?? '—']) }}
                    </span>
                </div>
            </form>
        </div>

        @if ($result)
            {{-- ملخّص --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="admin-card p-4">
                    <p class="text-lg font-bold text-gray-900">{{ number_format(count($result['rows'])) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $result['preview'] ? __('أصناف ستُنشأ') : __('أصناف أُنشئت') }}</p>
                </div>
                <div class="admin-card p-4">
                    <p class="text-lg font-bold text-gray-900">{{ number_format(array_sum(array_column($result['rows'], 'qty')), 2) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('إجمالي الكميات') }}</p>
                </div>
                <div class="admin-card p-4 border-2 border-indigo-200 bg-indigo-50">
                    <p class="text-lg font-bold text-indigo-700">{{ number_format($result['value'], 2) }} {{ $sym }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('قيمة المخزون الافتتاحي') }}</p>
                </div>
                <div class="admin-card p-4">
                    <p class="text-lg font-bold {{ $result['errors'] ? 'text-amber-600' : 'text-gray-400' }}">{{ count($result['errors']) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('أسطر متخطّاة') }}</p>
                </div>
            </div>

            @if (! $result['preview'] && $result['imported'])
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">
                    {{ __('تم الاستيراد: :n صنفًا، وقيمة المخزون :v :s مُرحّلة كرصيد افتتاحي (مدين المخزون / دائن رأس المال).', [
                        'n' => $result['imported']['created'], 'v' => number_format($result['imported']['value'], 2), 's' => $sym,
                    ]) }}
                </div>
            @elseif ($result['preview'])
                <div class="rounded-lg bg-sky-50 border border-sky-200 text-sky-800 text-sm px-4 py-3">
                    {{ __('معاينة فقط — لم يُحفظ شيء. راجع الجدول ثم اضغط «استيراد».') }}
                </div>
            @endif

            {{-- الأسطر المتخطّاة --}}
            @if ($result['errors'])
                <div class="admin-card p-5">
                    <h3 class="font-semibold text-amber-700 mb-3">{{ __('أسطر متخطّاة') }}</h3>
                    <ul class="list-disc pe-5 space-y-1 text-sm text-gray-600">
                        @foreach ($result['errors'] as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- التفصيل --}}
            <x-admin.table>
                <thead>
                    <tr>
                        <th>{{ __('اسم الصنف') }}</th>
                        <th>{{ __('الفئة') }}</th>
                        <th class="text-start">{{ __('الكمية') }}</th>
                        <th class="text-start">{{ __('سعر الشراء') }}</th>
                        <th class="text-start">{{ __('سعر الجملة') }}</th>
                        <th class="text-start">{{ __('سعر البيع') }}</th>
                        <th class="text-start">{{ __('القيمة') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $r)
                        <tr>
                            <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                            <td class="text-gray-500">{{ $r['category'] ?: '—' }}</td>
                            <td class="text-start tabular-nums">{{ number_format($r['qty'], 2) }}</td>
                            <td class="text-start tabular-nums">{{ number_format($r['cost_price'], 2) }}</td>
                            <td class="text-start tabular-nums">{{ number_format($r['wholesale_price'], 2) }}</td>
                            <td class="text-start tabular-nums">{{ number_format($r['retail_price'], 2) }}</td>
                            <td class="text-start tabular-nums font-medium">{{ number_format($r['value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="!p-0">
                            <x-admin.empty-state :title="__('لا أسطر صالحة')" :description="__('راجع الأسطر المتخطّاة أعلاه.')" />
                        </td></tr>
                    @endforelse
                </tbody>
            </x-admin.table>
        @endif
    </div>
</x-app-layout>
