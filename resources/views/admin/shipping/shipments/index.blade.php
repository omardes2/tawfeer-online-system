<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('الشحن') }} — {{ __('الشحنات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('الشحنات')">
                <div class="flex gap-2">
                    @can('settings.geography.view')
                        <a href="{{ route('admin.shipping.geography.index') }}" class="inline-flex px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('الجغرافيا') }}</a>
                    @endcan
                    @can('create', \App\Modules\Shipping\Models\Shipment::class)
                        <a href="{{ route('admin.shipping.shipments.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('شحنة جديدة') }}</a>
                    @endcan
                </div>
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الطلب') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستلِم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التكلفة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($shipments as $s)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $s->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $s->order?->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $s->recipient_name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $s->shipping_cost }} <span class="text-xs text-gray-400">({{ $s->cost_source }})</span></td>
                                <td class="py-2 px-3"><x-shipping.status :status="$s->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.shipping.shipments.show', $s) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد شحنات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $shipments->links() }}</div>
        </div>
    </div>
</x-app-layout>
