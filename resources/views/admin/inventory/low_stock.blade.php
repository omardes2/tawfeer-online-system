<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('warehouse.low_stock_title') }}</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
            <table class="min-w-full text-sm text-right">
                <thead class="text-gray-500 border-b"><tr>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.variant') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.on_hand') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.reorder_level') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.reorder_qty') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $s)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $s->variant?->product?->name ?? $s->variant?->sku }}
                                <span class="text-xs text-gray-400">{{ $s->variant?->sku }}</span></td>
                            <td class="py-2 px-3 font-bold text-rose-600">{{ (float) $s->on_hand }}</td>
                            <td class="py-2 px-3">{{ (float) $s->reorder_level }}</td>
                            <td class="py-2 px-3">{{ $s->reorder_qty !== null ? (float) $s->reorder_qty : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('warehouse.no_low_stock') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
