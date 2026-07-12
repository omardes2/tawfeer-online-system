<x-storefront.account-layout :title="$order->number" active="orders">
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <a href="{{ route('account.orders') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('account.my_orders') }}</a>
            <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $order->number }}</h1>
            <p class="text-xs text-gray-400">{{ $order->created_at?->format('Y-m-d H:i') }}</p>
        </div>
        <form method="POST" action="{{ route('account.orders.reorder', $order) }}">
            @csrf
            <button type="submit" class="rounded-lg border border-emerald-600 text-emerald-700 hover:bg-emerald-50 text-sm font-medium px-4 py-2">
                {{ __('account.reorder') }}
            </button>
        </form>
    </div>

    {{-- الحالة الحيّة (تحدَّث دوريًا) --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4"
         x-data="orderTracker('{{ route('account.orders.status', $order) }}')" x-init="start()">
        <div class="flex flex-wrap items-center gap-4">
            <div>
                <p class="text-xs text-gray-400 mb-1">{{ __('account.order_status') }}</p>
                <span x-show="loaded" x-cloak class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs text-gray-400">{{ __('account.live') }}</span>
                </span>
                <div class="mt-1"><x-sales.status :status="$order->status" /></div>
            </div>
            <div>
                <p class="text-xs text-gray-400 mb-1">{{ __('account.payment_status') }}</p>
                <x-payment.status :status="$order->payment_status" />
            </div>
            @if ($order->shipments->isNotEmpty())
                <div>
                    <p class="text-xs text-gray-400 mb-1">{{ __('account.shipment_status') }}</p>
                    <x-shipping.status :status="$order->shipments->last()->status" />
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- البنود --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-bold text-gray-900 mb-3">{{ __('account.items') }}</h2>
            <div class="divide-y divide-gray-100">
                @foreach ($order->items as $item)
                    <div class="flex items-center justify-between py-2 text-sm">
                        <span class="text-gray-700">{{ $item->variant?->sku ?? '—' }} × {{ (float) $item->qty }}</span>
                        <span class="font-medium text-gray-800">{{ number_format((float) $item->line_total, 2) }} {{ __('storefront.currency') }}</span>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-gray-200 mt-3 pt-3 flex items-center justify-between">
                <span class="font-bold text-gray-900">{{ __('account.order_total') }}</span>
                <span class="font-bold text-emerald-700">{{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}</span>
            </div>
        </div>

        {{-- المسار الزمني --}}
        <div class="lg:col-span-1 bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-bold text-gray-900 mb-3">{{ __('account.order_timeline') }}</h2>
            <ol class="relative border-s border-gray-200 ms-2 space-y-4">
                @foreach ($order->statusHistory->sortByDesc('id') as $history)
                    <li class="ms-4">
                        <span class="absolute -start-1.5 h-3 w-3 rounded-full bg-emerald-500 mt-1.5"></span>
                        <div><x-sales.status :status="$history->to_status" /></div>
                        <p class="text-xs text-gray-400 mt-1">{{ $history->created_at?->format('Y-m-d H:i') }}</p>
                        @if ($history->note)
                            <p class="text-xs text-gray-500 mt-0.5">{{ $history->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    <script>
        function orderTracker(url) {
            return {
                loaded: false,
                timer: null,
                start() {
                    this.poll();
                    this.timer = setInterval(() => this.poll(), 15000);
                },
                async poll() {
                    try {
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.ok) { await res.json(); this.loaded = true; }
                    } catch (e) { /* تجاهل — إعادة المحاولة لاحقًا */ }
                },
            };
        }
    </script>
</x-storefront.account-layout>
