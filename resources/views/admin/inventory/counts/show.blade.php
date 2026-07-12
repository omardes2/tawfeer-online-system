<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('warehouse.count') }} — {{ $count->number }}</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            <a href="{{ route('admin.inventory.counts.index') }}" class="text-sm text-gray-500 hover:text-indigo-600">← {{ __('warehouse.counts') }}</a>
            @if ($errors->any())
                <div class="mt-3 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            <div class="flex items-center gap-3 my-3">
                <span class="inline-block px-3 py-1 rounded-md bg-indigo-50 text-indigo-700 text-sm">{{ __('warehouse.status_label.'.$count->status) }}</span>
                <span class="text-sm text-gray-500">{{ $count->warehouse?->name }}</span>
            </div>

            {{-- إجراءات الحالة --}}
            @can('inventory.counts.manage')
                <div class="flex flex-wrap gap-2 border-t pt-4">
                    @if ($count->status === 'counting')
                        <form method="POST" action="{{ route('admin.inventory.counts.review', $count) }}">@csrf<button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('warehouse.review') }}</button></form>
                    @elseif ($count->status === 'review')
                        <form method="POST" action="{{ route('admin.inventory.counts.complete', $count) }}" onsubmit="return confirm('{{ __('warehouse.complete') }}?')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('warehouse.complete') }}</button></form>
                    @endif
                    @if (in_array($count->status, ['counting', 'review'], true))
                        <form method="POST" action="{{ route('admin.inventory.counts.cancel', $count) }}">@csrf<button class="px-3 py-2 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('warehouse.cancel') }}</button></form>
                    @endif
                </div>
            @endcan
        </div>

        {{-- مسح باركود لتسجيل العدّ --}}
        @if ($count->status === 'counting')
            @can('inventory.counts.manage')
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-gray-800 mb-3 text-sm">{{ __('warehouse.scan_barcode') }}</h3>
                    <form method="POST" action="{{ route('admin.inventory.counts.record', $count) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div><label class="block text-xs text-gray-500 mb-1">{{ __('warehouse.barcode') }}</label><input type="text" name="barcode" required class="rounded-md border-gray-300 text-sm"></div>
                        <div><label class="block text-xs text-gray-500 mb-1">{{ __('warehouse.counted_qty') }}</label><input type="number" step="0.001" min="0" name="counted_qty" required class="w-28 rounded-md border-gray-300 text-sm"></div>
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('warehouse.record') }}</button>
                    </form>
                </div>
            @endcan
        @endif

        {{-- بنود الجرد --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 overflow-x-auto">
            <table class="min-w-full text-sm text-right">
                <thead class="text-gray-500 border-b"><tr>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.variant') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.system_qty') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.counted_qty') }}</th>
                    <th class="py-2 px-3 font-medium">{{ __('warehouse.variance') }}</th>
                    @if ($count->status === 'counting')<th class="py-2 px-3"></th>@endif
                </tr></thead>
                <tbody>
                    @foreach ($count->items as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item->variant?->product?->name ?? $item->variant?->sku }}</td>
                            <td class="py-2 px-3">{{ (float) $item->system_qty }}</td>
                            <td class="py-2 px-3">{{ $item->counted_qty !== null ? (float) $item->counted_qty : '—' }}</td>
                            <td class="py-2 px-3 {{ (float) $item->variance != 0.0 ? 'text-rose-600 font-bold' : 'text-gray-400' }}">{{ $item->counted_qty !== null ? (float) $item->variance : '—' }}{{ $item->applied ? ' ✓' : '' }}</td>
                            @if ($count->status === 'counting')
                                @can('inventory.counts.manage')
                                    <td class="py-1 px-3">
                                        <form method="POST" action="{{ route('admin.inventory.counts.record', $count) }}" class="flex items-center gap-1">
                                            @csrf
                                            <input type="hidden" name="variant_id" value="{{ $item->variant_id }}">
                                            <input type="number" step="0.001" min="0" name="counted_qty" value="{{ $item->counted_qty !== null ? (float) $item->counted_qty : '' }}" class="w-24 rounded-md border-gray-300 text-xs">
                                            <button class="px-2 py-1 bg-gray-700 text-white text-xs rounded">{{ __('warehouse.record') }}</button>
                                        </form>
                                    </td>
                                @endcan
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
