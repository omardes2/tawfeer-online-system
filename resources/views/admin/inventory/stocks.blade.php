<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزن') }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))
    @php($num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') ?: '0')

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-admin.flash />

        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="flex items-center justify-between p-5 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">{{ __('المخزن') }}</h3>
            </div>

            {{-- بحث + فلتر الفئة --}}
            <form method="GET" class="flex flex-wrap items-center gap-2 px-5 py-4">
                <div class="relative flex-1 max-w-sm">
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم أو كود المنتج') }}"
                           class="w-full ps-9 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <svg class="w-4 h-4 text-gray-400 absolute top-2.5 start-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                </div>
                <select name="category" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('كل الفئات') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected($activeCategory == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-y border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('اسم الصنف') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الفئة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الكمية المتوفرة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('سعر البيع') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('سعر الجملة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('سعر الشراء') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('تعديل') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $p)
                            @php($available = (float) ($p->on_hand_sum ?? 0) - (float) ($p->reserved_sum ?? 0))
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4">
                                    <a href="{{ route('admin.inventory.products.show', $p) }}" class="text-gray-900 font-medium hover:text-emerald-700">{{ $p->name }}</a>
                                    <span class="block text-xs text-gray-400">{{ $p->sku }}</span>
                                </td>
                                <td class="py-3 px-4 text-gray-500">{{ $p->category?->name ?? '—' }}</td>
                                <td class="py-3 px-4">
                                    {{-- مطابقة: مجموع كميات المتغيّرات يجب أن يساوي إجمالي الصنف --}}
                                    @php($variants = $p->variants)
                                    @php($variantsAvailable = $variants->sum(fn ($v) => (float) ($v->on_hand_sum ?? 0) - (float) ($v->reserved_sum ?? 0)))
                                    @php($mismatch = abs($variantsAvailable - $available) > 0.001)
                                    <div x-data="{ open: false }">
                                        <button type="button" x-on:click="open = !open"
                                                class="inline-flex items-center gap-1 font-semibold tabular-nums {{ $available > 0 ? 'text-emerald-700' : 'text-gray-400' }} hover:underline"
                                                title="{{ __('عرض تفصيل المقاسات/المتغيّرات') }}">
                                            {{ $num($available) }}
                                            @if ($variants->count() > 1)
                                                <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                            @endif
                                        </button>

                                        @if ($variants->count() > 1)
                                            <div x-show="open" x-cloak class="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-2 min-w-[11rem]">
                                                @foreach ($variants as $v)
                                                    @php($vAvail = (float) ($v->on_hand_sum ?? 0) - (float) ($v->reserved_sum ?? 0))
                                                    <div class="flex items-center justify-between gap-4 text-xs py-0.5">
                                                        <span class="text-gray-600">{{ $v->optionLabel() }}</span>
                                                        <span class="tabular-nums font-medium {{ $vAvail > 0 ? 'text-gray-800' : 'text-gray-400' }}">{{ $num($vAvail) }}</span>
                                                    </div>
                                                @endforeach
                                                <div class="flex items-center justify-between gap-4 text-xs pt-1 mt-1 border-t border-gray-200">
                                                    <span class="text-gray-500">{{ __('المجموع') }}</span>
                                                    <span class="tabular-nums font-semibold {{ $mismatch ? 'text-rose-600' : 'text-emerald-700' }}">{{ $num($variantsAvailable) }}</span>
                                                </div>
                                                @if ($mismatch)
                                                    <p class="mt-1 text-[11px] text-rose-600">{{ __('لا يطابق إجمالي الصنف — راجع كرت الصنف.') }}</p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ $num($p->retail_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-600">{{ $num($p->wholesale_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-500">{{ $num($p->cost_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        @can('update', $p)
                                            <a href="{{ route('admin.inventory.products.edit', $p) }}" class="inline-flex text-gray-400 hover:text-emerald-600" title="{{ __('تعديل الصنف') }}">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                            </a>
                                        @endcan
                                        @can('delete', $p)
                                            <x-admin.confirm :action="route('admin.products.destroy', $p)" method="DELETE"
                                                :title="__('حذف الصنف')" :message="__('سيُحذف المنتج نهائيًا من الكتالوج والمخزون.')"
                                                :confirm="__('حذف')" tone="red">
                                                <x-slot name="trigger">
                                                    <span class="inline-flex text-gray-400 hover:text-rose-600" title="{{ __('حذف الصنف') }}">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </span>
                                                </x-slot>
                                            </x-admin.confirm>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-gray-400">{{ __('لا توجد أصناف.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-gray-100">{{ $products->links() }}</div>
        </div>
    </div>
</x-app-layout>
