@props(['title', 'items' => null, 'viewAll' => null])

{{--
    قسم عرض منتجات (تسويقي أو تصفّح). يُخفى تلقائيًا إن كانت المجموعة فارغة —
    يجعل أقسام النمو (related/cross-sell/bundles…) نقاط امتداد جاهزة بلا فوضى بصرية.
--}}
@if ($items === null || $items->isNotEmpty())
    <section class="py-4" {{ $attributes }}>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-900">{{ $title }}</h2>
            @if ($viewAll)
                <a href="{{ $viewAll }}" class="text-sm text-emerald-600 hover:underline">{{ __('storefront.view_all') }}</a>
            @endif
        </div>

        @if ($items !== null)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($items as $item)
                    <x-storefront.product-card :product="$item" />
                @endforeach
            </div>
        @else
            {{ $slot }}
        @endif
    </section>
@endif
