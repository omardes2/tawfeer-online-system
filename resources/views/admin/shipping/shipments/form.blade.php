<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('شحنة جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            @if (! $order)
                <p class="text-sm text-gray-600 mb-3">{{ __('اختر طلبًا مشحونًا لإنشاء شحنته:') }}</p>
                @if ($shippableOrders->isEmpty())
                    <p class="text-gray-400 text-sm">{{ __('لا توجد طلبات جاهزة للشحن.') }}</p>
                @else
                    <ul class="divide-y text-sm">
                        @foreach ($shippableOrders as $o)
                            <li class="py-2 flex items-center justify-between">
                                <span>{{ $o->number }} — {{ $o->customer_name }}</span>
                                <a href="{{ route('admin.shipping.shipments.create', ['order' => $o->uuid]) }}" class="text-indigo-600 hover:underline">{{ __('إنشاء شحنة') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <div class="flex flex-wrap gap-4 text-sm mb-4">
                    <span>{{ __('الطلب') }}: <b>{{ $order->number }}</b></span>
                    <span>{{ __('العميل') }}: <b>{{ $order->customer_name }}</b> ({{ $order->customer_phone }})</span>
                </div>
                <form method="POST" action="{{ route('admin.shipping.shipments.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="order" value="{{ $order->uuid }}" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-admin.field :label="__('شركة التوصيل')" name="carrier_name">
                            <input type="text" name="carrier_name" value="{{ old('carrier_name') }}" class="w-full rounded-md border-gray-300 text-sm" />
                        </x-admin.field>
                        <x-admin.field :label="__('رقم التتبّع')" name="tracking_number">
                            <input type="text" name="tracking_number" value="{{ old('tracking_number') }}" class="w-full rounded-md border-gray-300 text-sm" />
                        </x-admin.field>
                        <x-admin.field :label="__('المزوّد')" name="delivery_provider">
                            <select name="delivery_provider" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="">—</option>
                                @foreach ($providers as $p)<option value="{{ $p->uuid }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </x-admin.field>
                        @can('shipping.override_cost')
                            <x-admin.field :label="__('تكلفة الشحن (تجاوز يدوي)')" name="shipping_cost">
                                <input type="number" step="0.01" min="0" name="shipping_cost" value="{{ old('shipping_cost') }}" class="w-full rounded-md border-gray-300 text-sm" />
                            </x-admin.field>
                        @endcan
                    </div>
                    <x-admin.field :label="__('ملاحظات')" name="notes">
                        <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ old('notes') }}</textarea>
                    </x-admin.field>
                    <div class="flex gap-2 pt-2">
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('إنشاء الشحنة') }}</button>
                        <a href="{{ route('admin.shipping.shipments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
