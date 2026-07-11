<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('استلام بضاعة') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            @if (! $order)
                <p class="text-sm text-gray-600 mb-3">{{ __('اختر أمر شراء معتمدًا للاستلام منه:') }}</p>
                @if ($openOrders->isEmpty())
                    <p class="text-gray-400 text-sm">{{ __('لا توجد أوامر شراء جاهزة للاستلام.') }}</p>
                @else
                    <ul class="divide-y text-sm">
                        @foreach ($openOrders as $po)
                            <li class="py-2 flex items-center justify-between">
                                <span>{{ $po->number }} — {{ $po->supplier?->name }} <x-purchasing.status :status="$po->status" /></span>
                                <a href="{{ route('admin.purchasing.receipts.create', ['purchase_order' => $po->uuid]) }}" class="text-indigo-600 hover:underline">{{ __('استلام') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <div class="flex flex-wrap gap-4 text-sm mb-4">
                    <span>{{ __('أمر الشراء') }}: <b>{{ $order->number }}</b></span>
                    <span>{{ __('المورد') }}: <b>{{ $order->supplier?->name }}</b></span>
                    <span>{{ __('المستودع') }}: <b>{{ $order->warehouse?->name }}</b></span>
                </div>
                <form method="POST" action="{{ route('admin.purchasing.receipts.store') }}" class="space-y-5">
                    @csrf
                    <input type="hidden" name="purchase_order" value="{{ $order->uuid }}" />
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-right">
                            <thead class="text-gray-500 border-b"><tr>
                                <th class="py-2 px-3 font-medium">SKU</th>
                                <th class="py-2 px-3 font-medium">{{ __('المطلوب') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('المتبقّي') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('كمية الاستلام') }}</th>
                                <th class="py-2 px-3 font-medium">{{ __('تكلفة الوحدة') }}</th>
                            </tr></thead>
                            <tbody class="divide-y">
                                @foreach ($order->items as $i => $item)
                                    @php $remaining = (float) $item->qty_ordered - (float) $item->qty_received; @endphp
                                    <tr>
                                        <td class="py-2 px-3 text-gray-500">{{ $item->variant?->sku }}</td>
                                        <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_ordered, '0'), '.') }}</td>
                                        <td class="py-2 px-3">{{ rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') }}</td>
                                        <td class="py-2 px-3">
                                            <input type="hidden" name="items[{{ $i }}][variant]" value="{{ $item->variant?->uuid }}" />
                                            <input type="hidden" name="items[{{ $i }}][purchase_order_item_id]" value="{{ $item->id }}" />
                                            <input type="number" step="0.001" min="0" max="{{ $remaining }}" name="items[{{ $i }}][qty_received]" value="{{ max($remaining, 0) }}" class="rounded-md border-gray-300 text-sm w-28" />
                                        </td>
                                        <td class="py-2 px-3">
                                            <input type="number" step="0.0001" min="0" name="items[{{ $i }}][unit_cost]" value="{{ $item->unit_cost }}" required class="rounded-md border-gray-300 text-sm w-28" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @can('pricing.view_cost')
                            <x-admin.field :label="__('تكلفة إضافية (شحن/جمارك)')" name="additional_cost">
                                <input type="number" step="0.0001" min="0" name="additional_cost" value="{{ old('additional_cost', 0) }}" class="w-full rounded-md border-gray-300 text-sm" />
                            </x-admin.field>
                        @endcan
                        <x-admin.field :label="__('ملاحظات')" name="notes">
                            <input type="text" name="notes" value="{{ old('notes') }}" class="w-full rounded-md border-gray-300 text-sm" />
                        </x-admin.field>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('حفظ الاستلام (مسودّة)') }}</button>
                        <a href="{{ route('admin.purchasing.orders.show', $order) }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
