<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون') }} — {{ __('الأرصدة') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('أرصدة المخزون')" />
            <form method="GET" class="mb-4">
                <select name="warehouse" class="rounded-md border-gray-300 text-sm" onchange="this.form.submit()">
                    <option value="">{{ __('كل المستودعات') }}</option>
                    @foreach ($warehouses as $wh)<option value="{{ $wh->id }}" @selected(request('warehouse') == $wh->id)>{{ $wh->name }}</option>@endforeach
                </select>
            </form>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('المنتج') }}</th>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('متوفّر') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('محجوز') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('متاح') }}</th>
                        @can('pricing.view_cost')<th class="py-2 px-3 font-medium">{{ __('متوسط التكلفة') }}</th>@endcan
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($stocks as $stock)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $stock->variant?->product?->name ?? '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $stock->variant?->sku }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $stock->warehouse?->name }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($stock->on_hand, '0'), '.') }}</td>
                                <td class="py-2 px-3 text-amber-600">{{ rtrim(rtrim($stock->reserved, '0'), '.') }}</td>
                                <td class="py-2 px-3 font-medium text-emerald-700">{{ $stock->available }}</td>
                                @can('pricing.view_cost')<td class="py-2 px-3 text-gray-500">{{ $stock->average_cost }}</td>@endcan
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا توجد أرصدة.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $stocks->links() }}</div>
        </div>
    </div>
</x-app-layout>
