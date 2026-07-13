<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('returns.new') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            {{-- بحث عن الطلب --}}
            <form method="GET" action="{{ route('admin.returns.create') }}" class="flex items-end gap-2 mb-6 border-b pb-4">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('returns.lookup_order') }}</label>
                    <input type="text" name="order" value="{{ request('order') }}" class="w-full rounded-md border-gray-300 text-sm" placeholder="SO-2026-000001">
                </div>
                <button class="px-4 py-2 bg-gray-700 text-white text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            @if ($order)
                <form method="POST" action="{{ route('admin.returns.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="order" value="{{ $order->uuid }}">
                    <p class="text-sm text-gray-600">{{ __('returns.order') }}: <span class="font-mono">{{ $order->number }}</span> — {{ $order->status }}</p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('returns.type') }}</label>
                            <select name="type" class="w-full rounded-md border-gray-300 text-sm">
                                @foreach ($types as $t)<option value="{{ $t }}">{{ __('returns.type_label.'.$t) }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('returns.reason') }}</label>
                            <select name="reason_code" class="w-full rounded-md border-gray-300 text-sm">
                                @foreach ($reasons as $r)<option value="{{ $r }}">{{ __('returns.reason_label.'.$r) }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('returns.resolution') }}</label>
                            <select name="resolution" class="w-full rounded-md border-gray-300 text-sm">
                                @foreach ($resolutions as $r)<option value="{{ $r }}">{{ __('returns.resolution_label.'.$r) }}</option>@endforeach
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-right">
                            <thead class="text-gray-500 border-b"><tr>
                                <th class="py-2 px-3 font-medium">{{ __('returns.variant') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('returns.qty') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($order->items as $i => $item)
                                    <tr class="border-b">
                                        <td class="py-2 px-3">
                                            {{ $item->variant?->product?->name_ar ?? $item->variant?->sku }}
                                            <span class="text-xs text-gray-400">({{ (float) $item->qty - (float) $item->returned_qty }})</span>
                                            <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item->id }}">
                                        </td>
                                        <td class="py-2 px-3">
                                            <input type="number" step="0.001" min="0" max="{{ (float) $item->qty - (float) $item->returned_qty }}" name="items[{{ $i }}][qty]" value="0" class="w-24 rounded-md border-gray-300 text-sm">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('returns.notes') }}</label>
                        <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                    </div>

                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('returns.create') }}</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
