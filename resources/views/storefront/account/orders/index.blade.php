<x-storefront.account-layout :title="__('account.my_orders')" active="orders">
    <h1 class="sf-section-title mb-4">{{ __('account.my_orders') }}</h1>

    @if ($orders->isEmpty())
        <x-storefront.empty-state icon="box"
            :title="__('account.no_orders')"
            :description="__('account.no_orders_hint')"
            :action="route('storefront.shop')"
            :action-label="__('storefront.shop')" />
    @else
        {{-- الجوّال: بطاقات. الجدول هنا كان يفرض تمريرًا أفقيًا على الهاتف. --}}
        <div class="space-y-3 md:hidden">
            @foreach ($orders as $order)
                <a href="{{ route('account.orders.show', $order) }}"
                   class="sf-card sf-card-hover p-4 block">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold text-sm text-[color:var(--sf-text)]">{{ $order->number }}</p>
                            <p class="text-xs text-[color:var(--sf-text-soft)] mt-1">{{ $order->created_at?->format('Y-m-d') }}</p>
                        </div>
                        <x-sales.status :status="$order->status" />
                    </div>
                    <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-[color:var(--sf-border)]">
                        <span class="sf-price whitespace-nowrap">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</span>
                        <span class="sf-section-link">
                            {{ __('account.track_order') }}
                            <x-storefront.icon name="chevron-left" class="w-4 h-4 ltr:rotate-180" />
                        </span>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- الحواسيب: جدول --}}
        <div class="hidden md:block sf-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)] text-xs">
                    <tr>
                        <th class="text-start font-bold px-4 py-3">{{ __('account.order_number') }}</th>
                        <th class="text-start font-bold px-4 py-3">{{ __('account.order_date') }}</th>
                        <th class="text-start font-bold px-4 py-3">{{ __('account.order_status') }}</th>
                        <th class="text-end font-bold px-4 py-3">{{ __('account.order_total') }}</th>
                        <th class="px-4 py-3"><span class="sr-only">{{ __('account.track_order') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[color:var(--sf-border)]">
                    @foreach ($orders as $order)
                        <tr class="hover:bg-[color:var(--sf-bg)] transition-colors">
                            <td class="px-4 py-3 font-bold text-[color:var(--sf-text)]">{{ $order->number }}</td>
                            <td class="px-4 py-3 text-[color:var(--sf-text-soft)]">{{ $order->created_at?->format('Y-m-d') }}</td>
                            <td class="px-4 py-3"><x-sales.status :status="$order->status" /></td>
                            <td class="px-4 py-3 text-end sf-price whitespace-nowrap">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</td>
                            <td class="px-4 py-3 text-end">
                                <a href="{{ route('account.orders.show', $order) }}" class="sf-section-link">
                                    {{ __('account.track_order') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="mt-6" aria-label="{{ __('storefront.pagination') }}">{{ $orders->links('vendor.pagination.storefront') }}</nav>
    @endif
</x-storefront.account-layout>
