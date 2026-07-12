<x-storefront.account-layout :title="__('account.my_orders')" active="orders">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.my_orders') }}</h1>

    @if ($orders->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
            <p class="text-gray-700 font-medium">{{ __('account.no_orders') }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ __('account.no_orders_hint') }}</p>
            <a href="{{ route('storefront.shop') }}" class="inline-block mt-4 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('storefront.shop') }}</a>
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs">
                        <tr>
                            <th class="text-start font-medium px-4 py-3">{{ __('account.order_number') }}</th>
                            <th class="text-start font-medium px-4 py-3">{{ __('account.order_date') }}</th>
                            <th class="text-start font-medium px-4 py-3">{{ __('account.order_status') }}</th>
                            <th class="text-end font-medium px-4 py-3">{{ __('account.order_total') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($orders as $order)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $order->number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $order->created_at?->format('Y-m-d') }}</td>
                                <td class="px-4 py-3"><x-sales.status :status="$order->status" /></td>
                                <td class="px-4 py-3 text-end font-semibold text-gray-800">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</td>
                                <td class="px-4 py-3 text-end">
                                    <a href="{{ route('account.orders.show', $order) }}" class="text-emerald-600 hover:underline whitespace-nowrap">{{ __('account.track_order') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-storefront.account-layout>
