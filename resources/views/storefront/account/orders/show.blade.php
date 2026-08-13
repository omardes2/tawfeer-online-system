<x-storefront.account-layout :title="$order->number" active="orders">

    {{--
        إعادة تصميم بصرية. `orderTracker()` ومسار `account.orders.status`
        ودورية الاستطلاع كما هي حرفيًا. حالات الشحن تُعرَض عبر المكوّن القائم
        (`x-shipping.status`) دون المساس بمنطق التوصيل.
    --}}

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div class="min-w-0">
            <a href="{{ route('account.orders') }}" class="sf-section-link">
                <x-storefront.icon name="chevron-right" class="w-4 h-4 ltr:rotate-180" />
                {{ __('account.my_orders') }}
            </a>
            <h1 class="sf-section-title mt-1">{{ $order->number }}</h1>
            <p class="text-xs text-[color:var(--sf-text-soft)] mt-0.5">{{ $order->created_at?->format('Y-m-d H:i') }}</p>
        </div>

        <form method="POST" action="{{ route('account.orders.reorder', $order) }}">
            @csrf
            <button type="submit" class="sf-btn-outline">
                <x-storefront.icon name="refresh" class="w-4 h-4" />
                {{ __('account.reorder') }}
            </button>
        </form>
    </div>

    {{-- الحالة الحيّة (تُستطلع دوريًا) --}}
    <section class="sf-card sf-card-pad mb-4"
             x-data="orderTracker('{{ route('account.orders.status', $order) }}')" x-init="start()">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="font-bold text-[color:var(--sf-text)]">{{ __('account.status_now') }}</h2>
            <span x-show="loaded" x-cloak class="inline-flex items-center gap-1.5 text-xs text-[color:var(--sf-text-soft)]">
                <span class="h-2 w-2 rounded-full bg-[color:var(--sf-success)] animate-pulse"></span>
                {{ __('account.live') }}
            </span>
        </div>

        {{-- flex بدل شبكة ثلاثية: بلا شحنة تبقى بطاقتان فقط، والشبكة كانت تترك عمودًا فارغًا --}}
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[9rem] rounded-xl bg-[color:var(--sf-bg)] px-4 py-3">
                <p class="text-xs text-[color:var(--sf-text-soft)] mb-1.5">{{ __('account.order_status') }}</p>
                <x-sales.status :status="$order->status" />
            </div>
            <div class="flex-1 min-w-[9rem] rounded-xl bg-[color:var(--sf-bg)] px-4 py-3">
                <p class="text-xs text-[color:var(--sf-text-soft)] mb-1.5">{{ __('account.payment_status') }}</p>
                <x-payment.status :status="$order->payment_status" />
            </div>
            @if ($order->shipments->isNotEmpty())
                <div class="flex-1 min-w-[9rem] rounded-xl bg-[color:var(--sf-bg)] px-4 py-3">
                    <p class="text-xs text-[color:var(--sf-text-soft)] mb-1.5">{{ __('account.shipment_status') }}</p>
                    <x-shipping.status :status="$order->shipments->last()->status" />
                </div>
            @endif
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- البنود --}}
        <section class="lg:col-span-2 sf-card sf-card-pad">
            <h2 class="font-bold text-[color:var(--sf-text)] mb-3">{{ __('account.items') }}</h2>

            <ul class="divide-y divide-[color:var(--sf-border)]">
                @foreach ($order->items as $item)
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0 text-[color:var(--sf-text)]">
                            <span class="block font-semibold truncate">{{ $item->variant?->sku ?? '—' }}</span>
                            <span class="block text-xs text-[color:var(--sf-text-soft)] mt-0.5">
                                {{ __('account.quantity') }}: {{ (float) $item->qty }}
                            </span>
                        </span>
                        <span class="font-bold tabular-nums whitespace-nowrap text-[color:var(--sf-text)]">
                            {{ number_format((float) $item->line_total, 2) }} {{ __('storefront.currency') }}
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-[color:var(--sf-border)] mt-3 pt-3 flex items-center justify-between gap-3">
                <span class="font-bold text-[color:var(--sf-text)]">{{ __('account.order_total') }}</span>
                <span class="sf-price text-xl whitespace-nowrap">
                    {{ number_format((float) $order->total, 2) }} {{ __('storefront.currency') }}
                </span>
            </div>
        </section>

        {{-- المسار الزمني --}}
        <section class="sf-card sf-card-pad">
            <h2 class="font-bold text-[color:var(--sf-text)] mb-3">{{ __('account.order_timeline') }}</h2>
            <ol class="sf-timeline">
                @foreach ($order->statusHistory->sortByDesc('id') as $history)
                    <li>
                        <x-sales.status :status="$history->to_status" />
                        <p class="text-xs text-[color:var(--sf-text-soft)] mt-1">{{ $history->created_at?->format('Y-m-d H:i') }}</p>
                        @if ($history->note)
                            <p class="text-xs text-[color:var(--sf-text-soft)] mt-0.5">{{ $history->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    {{-- ⚠️ لا يُمسّ: منطق الاستطلاع كما هو حرفيًا --}}
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
