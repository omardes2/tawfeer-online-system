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
                                    <span class="font-semibold tabular-nums {{ $available > 0 ? 'text-emerald-700' : 'text-gray-400' }}">{{ $num($available) }}</span>
                                </td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ $num($p->retail_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-600">{{ $num($p->wholesale_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-500">{{ $num($p->cost_price) }} {{ $currency }}</td>
                                <td class="py-3 px-4 text-center">
                                    @can('update', $p)
                                        <a href="{{ route('admin.inventory.products.edit', $p) }}" class="inline-flex text-gray-400 hover:text-emerald-600" title="{{ __('تعديل الصنف') }}">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </a>
                                    @endcan
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
