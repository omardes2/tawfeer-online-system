<x-storefront.account-layout :title="__('account.dashboard')" active="dashboard">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.dashboard') }}</h1>

    {{-- بطاقات ملخّص --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <a href="{{ route('account.orders') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-emerald-300">
            <p class="text-2xl font-bold text-gray-900">{{ $recentOrders->count() ? $customer->orders()->count() : 0 }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('account.total_orders') }}</p>
        </a>
        <a href="{{ route('account.wishlist') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-emerald-300">
            <p class="text-2xl font-bold text-gray-900">{{ $wishlistCount }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('account.wishlist_items') }}</p>
        </a>
        <a href="{{ route('account.addresses') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-emerald-300">
            <p class="text-2xl font-bold text-gray-900">{{ $addressCount }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('account.saved_addresses') }}</p>
        </a>
        <a href="{{ route('account.notifications') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-emerald-300">
            <p class="text-2xl font-bold text-gray-900">{{ $unreadCount }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('account.unread_notifications') }}</p>
        </a>
    </div>

    {{-- أحدث الطلبات --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-gray-900">{{ __('account.recent_orders') }}</h2>
            <a href="{{ route('account.orders') }}" class="text-sm text-emerald-600 hover:underline">{{ __('account.view_all') }}</a>
        </div>
        @if ($recentOrders->isEmpty())
            <p class="text-sm text-gray-500 text-center py-6">{{ __('account.no_orders') }}</p>
        @else
            <div class="divide-y divide-gray-100">
                @foreach ($recentOrders as $order)
                    <a href="{{ route('account.orders.show', $order) }}" class="flex items-center justify-between py-3 hover:bg-gray-50 -mx-2 px-2 rounded">
                        <div>
                            <p class="font-medium text-gray-800">{{ $order->number }}</p>
                            <p class="text-xs text-gray-400">{{ $order->created_at?->format('Y-m-d') }}</p>
                        </div>
                        <div class="text-end">
                            <p class="font-semibold text-gray-800">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</p>
                            <x-sales.status :status="$order->status" />
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-storefront.account-layout>
