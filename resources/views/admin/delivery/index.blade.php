<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('delivery.engine') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />

            {{-- تقرير أسباب التعليق (قابل للتقرير) --}}
            @if ($holdReasons->isNotEmpty())
                <div class="mb-6 border-b pb-4">
                    <h3 class="font-semibold text-gray-800 mb-2">{{ __('delivery.hold_reasons_report') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($holdReasons as $r)
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-amber-50 text-amber-800 text-xs">
                                {{ __('delivery.reason_label.'.$r->reason_code) }}
                                <span class="font-bold">{{ $r->total }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- فلترة بالحالة القانونية --}}
            <div class="flex flex-wrap gap-1 mb-4">
                <a href="{{ route('admin.shipping.delivery.index') }}"
                   @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => ! $status, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => (bool) $status])>
                    {{ __('delivery.all_statuses') }}
                </a>
                @foreach ($statuses as $s)
                    <a href="{{ route('admin.shipping.delivery.index', ['delivery_status' => $s]) }}"
                       @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => $status === $s, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== $s])>
                        {{ __('delivery.status.'.$s) }}
                    </a>
                @endforeach
            </div>

            @if ($shipments->isEmpty())
                <p class="text-gray-500 text-center py-8">{{ __('delivery.no_shipments') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.shipment') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.order') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.delivery_status') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.provider_status') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.actions') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($shipments as $shipment)
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-3 font-mono text-xs">{{ $shipment->number }}</td>
                                    <td class="py-2 px-3">{{ $shipment->order?->number }}</td>
                                    <td class="py-2 px-3">
                                        <span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 text-xs">{{ __('delivery.status.'.$shipment->delivery_status) }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-gray-400 text-xs">{{ $shipment->provider_status ?? '—' }}</td>
                                    <td class="py-2 px-3">
                                        <a href="{{ route('admin.shipping.delivery.show', $shipment) }}" class="text-indigo-600 hover:underline">{{ __('delivery.view') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $shipments->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
