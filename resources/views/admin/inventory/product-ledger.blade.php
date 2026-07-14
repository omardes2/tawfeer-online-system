<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزن') }} — {{ $product->name }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))
    @php($num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') ?: '0')
    @php($typeLabels = [
        'purchase_in' => __('إدخال (شراء)'), 'sale_out' => __('صرف (بيع)'),
        'adjustment_in' => __('تسوية زيادة'), 'adjustment_out' => __('تسوية نقص'),
        'transfer_in' => __('تحويل وارد'), 'transfer_out' => __('تحويل صادر'),
        'purchase_return_out' => __('مرتجع مشتريات'), 'return_in' => __('مرتجع عميل'),
        'damage_out' => __('تالف'), 'reserve' => __('حجز'), 'release' => __('تحرير حجز'),
    ])

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-admin.flash />

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.inventory.stocks') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('عودة للمخزن') }}
            </a>
            @can('update', $product)
                <a href="{{ route('admin.inventory.products.edit', $product) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50">{{ __('تعديل الصنف') }}</a>
            @endcan
        </div>

        {{-- كرت الصنف --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-col lg:flex-row lg:items-center gap-6">
                <div class="flex items-center gap-4 flex-1">
                    <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-50 text-emerald-600 text-xl font-bold shrink-0">{{ mb_substr($product->name, 0, 1) }}</span>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">{{ $product->name }}</h3>
                        <div class="text-sm text-gray-500 mt-0.5 space-y-0.5">
                            <div>{{ __('كود المنتج') }}: <span class="text-gray-700">{{ $product->sku }}</span></div>
                            <div>{{ __('الفئة') }}: <span class="text-gray-700">{{ $product->category?->name ?? '—' }}</span></div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 lg:w-[420px]">
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('سعر البيع') }}</div>
                        <div class="text-base font-bold text-gray-900 tabular-nums mt-1">{{ $num($product->retail_price) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('سعر الجملة') }}</div>
                        <div class="text-base font-bold text-gray-700 tabular-nums mt-1">{{ $num($product->wholesale_price) }}</div>
                    </div>
                    <div class="rounded-lg p-3 text-center {{ $available > 0 ? 'bg-emerald-50' : 'bg-gray-50' }}">
                        <div class="text-xs text-gray-500">{{ __('الكمية المتوفرة') }}</div>
                        <div class="text-base font-bold tabular-nums mt-1 {{ $available > 0 ? 'text-emerald-700' : 'text-gray-900' }}">{{ $num($available) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- سجل المخزن --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-5 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">{{ __('سجل المخزن') }}</h3>
                <p class="text-sm text-gray-400">{{ __('جميع الحركات التي حصلت على الصنف.') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('نوع الحركة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المرجع') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المستودع') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الكمية المنقولة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الرصيد بعدها') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('القيمة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($ledger as $row)
                            @php($inc = (float) $row->qty_change >= 0)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-500 whitespace-nowrap">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $inc ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $typeLabels[$row->movement_type] ?? $row->movement_type }}</span>
                                </td>
                                <td class="py-3 px-4 text-gray-500">{{ $row->movement?->reason ?? '—' }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $row->warehouse?->name ?? '—' }}</td>
                                <td class="py-3 px-4 tabular-nums font-medium {{ $inc ? 'text-emerald-600' : 'text-rose-600' }}">{{ $inc ? '+' : '' }}{{ $num($row->qty_change) }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ $num($row->balance_after) }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-500">{{ $num($row->value_change) }} {{ $currency }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-gray-400">{{ __('لا توجد حركات على هذا الصنف بعد.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100">{{ $ledger->links() }}</div>
        </div>
    </div>
</x-app-layout>
