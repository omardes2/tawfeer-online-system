<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('شحنة') }} {{ $shipment->number }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('الطلب') }}: <b>{{ $shipment->order?->number }}</b></span>
                <span>{{ __('المستلِم') }}: <b>{{ $shipment->recipient_name }}</b> ({{ $shipment->recipient_phone }})</span>
                <span>{{ __('الحالة') }}: <x-shipping.status :status="$shipment->status" /></span>
            </div>
            <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                <span>{{ __('شركة التوصيل') }}: {{ $shipment->carrier_name ?: '—' }}</span>
                <span>{{ __('التتبّع') }}: {{ $shipment->tracking_number ?: '—' }}</span>
                <span>{{ __('التكلفة') }}: <b>{{ $shipment->shipping_cost }}</b> <span class="text-xs text-gray-400">({{ $shipment->cost_source }})</span></span>
                <span>{{ __('المحاولات') }}: {{ $shipment->delivery_attempts }}</span>
            </div>
            @if ($shipment->address_text)
                <p class="text-sm text-gray-500">{{ __('العنوان') }}: {{ $shipment->address_text }}</p>
            @endif
            @if ($shipment->failure_reason)
                <p class="text-sm text-rose-600">{{ __('سبب الفشل') }}: {{ $shipment->failure_reason }}</p>
            @endif

            {{-- أزرار الانتقالات حسب الحالة --}}
            <div class="flex flex-wrap gap-2 pt-2 border-t" x-data="{ failing: false }">
                @if (in_array($shipment->status, ['not_shipped', 'preparing']))
                    @can('dispatchShipment', $shipment)
                        <form method="POST" action="{{ route('admin.shipping.shipments.dispatch', $shipment) }}">@csrf<button class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">{{ __('إرسال') }}</button></form>
                    @endcan
                @endif
                @if (in_array($shipment->status, ['in_transit', 'delayed', 'customer_unavailable']))
                    @can('dispatchShipment', $shipment)
                        <form method="POST" action="{{ route('admin.shipping.shipments.out_for_delivery', $shipment) }}">@csrf<button class="px-4 py-2 bg-violet-600 text-white text-sm rounded-md hover:bg-violet-700">{{ __('خروج للتوصيل') }}</button></form>
                    @endcan
                @endif
                @if (in_array($shipment->status, ['in_transit', 'out_for_delivery', 'delayed', 'customer_unavailable']))
                    @can('deliver', $shipment)
                        <form method="POST" action="{{ route('admin.shipping.shipments.deliver', $shipment) }}" onsubmit="return confirm('{{ __('تأكيد التسليم؟') }}')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تسليم') }}</button></form>
                    @endcan
                    @can('fail', $shipment)
                        <button type="button" @click="failing = true" x-show="!failing" class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('فشل التسليم') }}</button>
                        <form method="POST" action="{{ route('admin.shipping.shipments.fail', $shipment) }}" x-show="failing" x-cloak class="flex gap-2 items-center">@csrf
                            <input type="text" name="reason" required placeholder="{{ __('سبب الفشل') }}" class="rounded-md border-gray-300 text-sm" />
                            <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">{{ __('تأكيد') }}</button>
                        </form>
                    @endcan
                @endif
                <a href="{{ route('admin.shipping.shipments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>

            {{-- سجلّ الأحداث --}}
            <div class="border-t pt-3">
                <h3 class="text-sm font-medium text-gray-700 mb-2">{{ __('سجلّ الأحداث') }}</h3>
                <ul class="text-sm space-y-1">
                    @foreach ($shipment->events as $e)
                        <li class="text-gray-600">
                            <span class="text-gray-400">{{ $e->created_at?->format('Y-m-d H:i') }}</span>
                            — <x-shipping.status :status="$e->to_status" />
                            @if ($e->changedBy) <span class="text-gray-400">({{ $e->changedBy->name }})</span> @endif
                            @if ($e->note) <span class="text-gray-500">— {{ $e->note }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
