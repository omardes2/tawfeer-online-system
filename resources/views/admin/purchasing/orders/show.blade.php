<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('أمر شراء') }} {{ $order->number }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('المورد') }}: <b>{{ $order->supplier?->name }}</b></span>
                <span>{{ __('المستودع') }}: <b>{{ $order->warehouse?->name }}</b></span>
                <span>{{ __('التاريخ') }}: {{ $order->order_date?->toDateString() }}</span>
                <span>{{ __('الحالة') }}: <x-purchasing.status :status="$order->status" /></span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('المطلوب') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستلم') }}</th>
                        @can('pricing.view_cost')
                            <th class="py-2 px-3 font-medium">{{ __('التكلفة') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الإجمالي') }}</th>
                        @endcan
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-2 px-3 text-gray-500">{{ $item->variant?->sku }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_ordered, '0'), '.') }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_received, '0'), '.') }}</td>
                                @can('pricing.view_cost')
                                    <td class="py-2 px-3">{{ $item->unit_cost }}</td>
                                    <td class="py-2 px-3">{{ $item->line_total }}</td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @can('pricing.view_cost')
                <div class="flex justify-end gap-6 text-sm border-t pt-3">
                    <span>{{ __('الإجمالي الفرعي') }}: <b>{{ $order->subtotal }}</b></span>
                    <span>{{ __('الخصم') }}: <b>{{ $order->discount_total }}</b></span>
                    <span>{{ __('الإجمالي') }}: <b>{{ $order->total }}</b></span>
                </div>
            @endcan

            <div class="flex flex-wrap gap-2 pt-2 border-t">
                @if ($order->status === 'draft')
                    @can('update', $order)
                        <form method="POST" action="{{ route('admin.purchasing.orders.submit', $order) }}">@csrf<button class="px-4 py-2 bg-amber-500 text-white text-sm rounded-md hover:bg-amber-600">{{ __('إرسال للاعتماد') }}</button></form>
                    @endcan
                @endif
                @if (in_array($order->status, ['draft', 'pending_approval']))
                    @can('approve', $order)
                        <form method="POST" action="{{ route('admin.purchasing.orders.approve', $order) }}">@csrf<button class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">{{ __('اعتماد') }}</button></form>
                    @endcan
                @endif
                @if (in_array($order->status, ['approved', 'partially_received']))
                    @can('create', \App\Modules\Purchasing\Models\GoodsReceipt::class)
                        <a href="{{ route('admin.purchasing.receipts.create', ['purchase_order' => $order->uuid]) }}" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('استلام بضاعة') }}</a>
                    @endcan
                @endif
                @if (in_array($order->status, ['received', 'partially_received']))
                    @can('close', $order)
                        <form method="POST" action="{{ route('admin.purchasing.orders.close', $order) }}">@csrf<button class="px-4 py-2 bg-gray-600 text-white text-sm rounded-md hover:bg-gray-700">{{ __('إغلاق') }}</button></form>
                    @endcan
                @endif
                @if (in_array($order->status, ['draft', 'pending_approval', 'approved']))
                    @can('cancel', $order)
                        <form method="POST" action="{{ route('admin.purchasing.orders.cancel', $order) }}" onsubmit="return confirm('{{ __('إلغاء أمر الشراء؟') }}')">@csrf<button class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('إلغاء الأمر') }}</button></form>
                    @endcan
                @endif
                <a href="{{ route('admin.purchasing.orders.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>

            @if ($order->receipts->isNotEmpty())
                <div class="border-t pt-3">
                    <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('إيصالات الاستلام') }}</h3>
                    <ul class="text-sm space-y-1">
                        @foreach ($order->receipts as $r)
                            <li><a href="{{ route('admin.purchasing.receipts.show', $r) }}" class="text-emerald-600 hover:underline">{{ $r->number }}</a> — <x-purchasing.status :status="$r->status" /></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
