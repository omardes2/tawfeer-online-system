<x-storefront.account-layout :title="__('account.dashboard')" active="dashboard">
    <h1 class="sf-section-title mb-4">{{ __('account.dashboard') }}</h1>

    {{-- بطاقات ملخّص — كلٌّ منها مدخل إلى صفحته --}}
    @php
        $stats = [
            ['route' => 'account.orders', 'icon' => 'box', 'label' => __('account.total_orders'),
             'value' => $recentOrders->count() ? $customer->orders()->count() : 0],
            ['route' => 'account.wishlist', 'icon' => 'heart', 'label' => __('account.wishlist_items'),
             'value' => $wishlistCount],
            ['route' => 'account.addresses', 'icon' => 'map-pin', 'label' => __('account.saved_addresses'),
             'value' => $addressCount],
            ['route' => 'account.notifications', 'icon' => 'bell', 'label' => __('account.unread_notifications'),
             'value' => $unreadCount],
        ];
    @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" class="sf-stat">
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand-50 text-brand-600 mb-1">
                    <x-storefront.icon :name="$stat['icon']" class="w-5 h-5" />
                </span>
                <span class="sf-stat-value">{{ number_format((int) $stat['value']) }}</span>
                <span class="sf-stat-label">{{ $stat['label'] }}</span>
            </a>
        @endforeach
    </div>

    {{-- أحدث الطلبات --}}
    <section class="sf-card sf-card-pad">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="font-bold text-[color:var(--sf-text)]">{{ __('account.recent_orders') }}</h2>
            @if ($recentOrders->isNotEmpty())
                <a href="{{ route('account.orders') }}" class="sf-section-link">
                    {{ __('account.view_all') }}
                    <x-storefront.icon name="chevron-left" class="w-4 h-4 ltr:rotate-180" />
                </a>
            @endif
        </div>

        @if ($recentOrders->isEmpty())
            <div class="py-8 text-center">
                <span class="mx-auto mb-3 grid place-items-center w-14 h-14 rounded-full bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)]">
                    <x-storefront.icon name="box" class="w-7 h-7" />
                </span>
                <p class="text-sm text-[color:var(--sf-text-soft)]">{{ __('account.no_orders') }}</p>
                <a href="{{ route('storefront.shop') }}" class="sf-btn-primary mt-4">{{ __('storefront.shop') }}</a>
            </div>
        @else
            <ul class="divide-y divide-[color:var(--sf-border)] -my-2">
                @foreach ($recentOrders as $order)
                    <li>
                        <a href="{{ route('account.orders.show', $order) }}"
                           class="flex items-center justify-between gap-3 py-3 -mx-2 px-2 rounded-xl hover:bg-[color:var(--sf-bg)] transition-colors">
                            <span class="min-w-0">
                                <span class="block font-bold text-sm text-[color:var(--sf-text)]">{{ $order->number }}</span>
                                <span class="block text-xs text-[color:var(--sf-text-soft)] mt-0.5">{{ $order->created_at?->format('Y-m-d') }}</span>
                            </span>
                            <span class="text-end shrink-0">
                                <span class="block sf-price text-sm whitespace-nowrap">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</span>
                                <span class="block mt-1"><x-sales.status :status="$order->status" /></span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-storefront.account-layout>
