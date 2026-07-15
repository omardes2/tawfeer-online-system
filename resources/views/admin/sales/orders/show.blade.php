<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('طلب بيع') }} {{ $order->number }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('العميل') }}: <b>{{ $order->customer_name }}</b> ({{ $order->customer_phone }})</span>
                <span>{{ __('المستودع') }}: <b>{{ $order->warehouse?->name }}</b></span>
                <span>{{ __('الحالة') }}: <x-sales.status :status="$order->status" /></span>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                @if ($order->city)<span>{{ __('المدينة') }}: <b>{{ $order->city->name }}</b></span>@endif
                @if ($order->area)<span>{{ __('المنطقة') }}: <b>{{ $order->area->name }}</b></span>@endif
                @if ($order->shipping_total > 0)<span>{{ __('رسوم التوصيل') }}: <b>{{ $order->shipping_total }}</b></span>@endif
                @if ($order->tracking_number)<span>{{ __('رقم التتبّع') }}: <b class="font-mono">{{ $order->tracking_number }}</b></span>@endif
            </div>
            @if ($order->shipping_address)
                <p class="text-sm text-gray-500">{{ __('العنوان') }}: {{ $order->shipping_address }}</p>
            @endif
            @if ($order->has_return)
                <p class="text-sm text-amber-700">{{ __('يوجد مرتجع') }}@if ($order->return_notes) — {{ $order->return_notes }}@endif</p>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الصنف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الكمية') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('السعر') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الخصم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الإجمالي') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('محجوز') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('مشحون') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">
                                    {{ $item->variant?->product?->name ?? $item->variant?->name ?? $item->variant?->sku ?? __('صنف محذوف') }}
                                    @if ($item->variant?->sku)
                                        <span class="block text-xs text-gray-400 font-mono">{{ $item->variant->sku }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty, '0'), '.') }}</td>
                                <td class="py-2 px-3">{{ $item->unit_price }}</td>
                                <td class="py-2 px-3">{{ $item->discount }}</td>
                                <td class="py-2 px-3">{{ $item->line_total }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_reserved, '0'), '.') }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_shipped, '0'), '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-6 text-sm border-t pt-3">
                <span>{{ __('الإجمالي الفرعي') }}: <b>{{ $order->subtotal }}</b></span>
                <span>{{ __('الخصم') }}: <b>{{ $order->discount_total }}</b></span>
                <span>{{ __('الإجمالي') }}: <b>{{ $order->total }}</b></span>
            </div>

            {{-- أزرار الانتقالات حسب الحالة --}}
            <div class="flex flex-wrap gap-2 pt-2 border-t" x-data="{ cancelling: false }">
                @if (in_array($order->status, ['draft', 'new']))
                    @can('confirm', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.confirm', $order) }}">@csrf<button class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">{{ __('تأكيد') }}</button></form>
                    @endcan
                @endif
                {{-- تعديل بيانات التواصل/التوصيل (تصحيح بيانات خاطئة) قبل الإرسال لشركة التوصيل --}}
                @if (\App\Http\Controllers\Admin\Sales\OrderController::isEditable($order))
                    @can('update', $order)
                        <a href="{{ route('admin.sales.orders.edit', $order) }}" class="px-4 py-2 bg-amber-100 text-amber-800 text-sm rounded-md hover:bg-amber-200">{{ __('تعديل الطلب') }}</a>
                    @endcan
                @endif
                @if ($order->status === 'confirmed')
                    @can('reserve', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.reserve', $order) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حجز المخزون') }}</button></form>
                    @endcan
                @endif
                {{-- إعادة إرسال لشركة التوصيل يدويًا: تظهر فقط لطلب توصيل مؤكّد لم يظهر له رقم تتبّع بعد. --}}
                @if (empty($order->tracking_number) && $order->channel !== 'pos' && $order->city_id && config('shipping.provider', 'null') !== 'null'
                    && ! in_array($order->status, ['draft', 'new', 'cancelled', 'delivered', 'returned']))
                    @can('confirm', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.resend_shipment', $order) }}">@csrf<button class="px-4 py-2 bg-amber-500 text-white text-sm rounded-md hover:bg-amber-600">{{ __('إرسال لشركة التوصيل') }}</button></form>
                    @endcan
                @endif
                @if ($order->status === 'stock_reserved')
                    @can('ship', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.prepare', $order) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('بدء التجهيز') }}</button></form>
                    @endcan
                @endif
                @if ($order->status === 'preparing')
                    @can('ship', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.ready', $order) }}">@csrf<button class="px-4 py-2 bg-violet-600 text-white text-sm rounded-md hover:bg-violet-700">{{ __('جاهز للشحن') }}</button></form>
                    @endcan
                @endif
                @if ($order->status === 'ready_to_ship')
                    @can('ship', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.ship', $order) }}" onsubmit="return confirm('{{ __('شحن الطلب وخصم المخزون؟') }}')">@csrf<button class="px-4 py-2 bg-violet-600 text-white text-sm rounded-md hover:bg-violet-700">{{ __('شحن') }}</button></form>
                    @endcan
                @endif
                @if ($order->status === 'shipped')
                    @can('deliver', $order)
                        <form method="POST" action="{{ route('admin.sales.orders.deliver', $order) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تسليم') }}</button></form>
                    @endcan
                @endif
                @if (! in_array($order->status, ['cancelled', 'delivered', 'returned']) && $order->channel !== 'pos')
                    @can('cancel', $order)
                        <button type="button" @click="cancelling = true" x-show="!cancelling" class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('إلغاء الطلب') }}</button>
                        <form method="POST" action="{{ route('admin.sales.orders.cancel', $order) }}" x-show="cancelling" x-cloak class="flex gap-2 items-center">@csrf
                            <input type="text" name="reason" required placeholder="{{ __('سبب الإلغاء') }}" class="rounded-md border-gray-300 text-sm" />
                            <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">{{ __('تأكيد الإلغاء') }}</button>
                        </form>
                    @endcan
                @endif
                <a href="{{ route('admin.sales.orders.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>

            {{-- سجلّ الحالات --}}
            <div class="border-t pt-3">
                <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('سجلّ الحالات') }}</h3>
                <ul class="text-sm space-y-1">
                    @foreach ($order->statusHistory as $h)
                        <li class="text-gray-600">
                            <span class="text-gray-400">{{ $h->created_at?->format('Y-m-d H:i') }}</span>
                            — <x-sales.status :status="$h->to_status" />
                            @if ($h->changedBy) <span class="text-gray-400">({{ $h->changedBy->name }})</span> @endif
                            @if ($h->note) <span class="text-gray-500">— {{ $h->note }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
