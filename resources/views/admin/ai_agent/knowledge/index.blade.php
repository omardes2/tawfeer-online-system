<x-app-layout :title="__('المعرفة البيعية للأصناف')">
    <x-admin.flash />

    <x-admin.header
        :title="__('المعرفة البيعية للأصناف')"
        :description="__('ما يقوله وكيل المبيعات عن كل صنف — لا ما يقوله الكتالوج.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المعرفة البيعية') => null]" />

    {{--
        عدّاد الجاهزية أولًا: «١٢ من ٨٠» يقول لصاحب النظام أين هو من التجهيز
        في سطر، بدل أن يتصفّح القائمة ليعدّ بنفسه.
    --}}
    <div class="admin-card p-4 mb-5">
        <p class="text-sm text-gray-700">
            {{ __('الأصناف الجاهزة للبيع بالوكيل:') }}
            <span class="font-bold text-emerald-700 tabular-nums">{{ $readyCount }}</span>
            <span class="text-gray-400">{{ __('من') }}</span>
            <span class="tabular-nums">{{ $totalCount }}</span>
        </p>
        <p class="mt-1 text-xs text-gray-500">
            {{ __('الصنف غير الجاهز لا يظهر في بحث الوكيل، ويُحوَّل السؤال عنه إلى موظفة — وذلك مقصود: الصمت أفضل من كلامٍ مخترَع باسم الشركة.') }}
        </p>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex-1 min-w-[16rem]">
            <label class="block text-xs text-gray-500 mb-1">{{ __('بحث عن صنف') }}</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('الاسم أو SKU…') }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الجاهزية') }}</label>
            <select name="ready" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm min-w-[10rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('الكل') }}</option>
                <option value="yes" @selected($ready === 'yes')>{{ __('جاهزة') }}</option>
                <option value="no" @selected($ready === 'no')>{{ __('غير جاهزة') }}</option>
            </select>
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('تطبيق') }}</button>
    </form>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('الصورة') }}</th>
                        <th>{{ __('الصنف') }}</th>
                        <th>{{ __('الفئة') }}</th>
                        <th>{{ __('الجاهزية') }}</th>
                        <th class="text-start">{{ __('نقاط البيع') }}</th>
                        <th>{{ __('إجراء') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php $k = $product->knowledge; @endphp
                        <tr>
                            <td data-label="{{ __('الصورة') }}">
                                @if ($product->primaryImage)
                                    {{-- `url()` دالّة لا خاصّية: `->url` يجعل Eloquent يبحث عن عمودٍ
                                         ثم علاقةٍ بهذا الاسم، فيرمي LogicException. --}}
                                    <img src="{{ $product->primaryImage->url() }}" alt=""
                                         class="w-11 h-11 rounded-md object-cover" loading="lazy" />
                                @else
                                    <span class="grid place-items-center w-11 h-11 rounded-md bg-gray-100 text-gray-300 text-xs">—</span>
                                @endif
                            </td>
                            <td data-label="{{ __('الصنف') }}" class="font-medium text-gray-800">
                                {{ $product->name }}
                                <span class="block text-[11px] text-gray-400 font-mono">{{ $product->sku }}</span>
                            </td>
                            <td data-label="{{ __('الفئة') }}" class="text-gray-500">{{ $product->category?->name ?? '—' }}</td>
                            <td data-label="{{ __('الجاهزية') }}">
                                @if ($k?->is_ready)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-emerald-50 text-emerald-700 ring-emerald-200">{{ __('جاهز') }}</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-gray-50 text-gray-500 ring-gray-200">{{ __('غير جاهز') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('نقاط البيع') }}" class="text-start tabular-nums text-gray-600">
                                {{ count($k?->selling_points ?? []) }}
                            </td>
                            <td data-label="{{ __('إجراء') }}">
                                <a href="{{ route('admin.ai_agent.knowledge.edit', $product) }}" class="btn-secondary btn-sm">
                                    {{ $k ? __('تعديل') : __('كتابة') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty-state
                                    :title="__('لا أصناف')"
                                    :description="__('أضِف أصنافًا من شاشة المنتجات ثم اكتب لها معرفتها البيعية هنا.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
