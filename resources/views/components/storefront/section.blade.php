@props(['title', 'items' => null, 'viewAll' => null, 'recoType' => null, 'placement' => null, 'source' => null])

{{--
    قسم عرض منتجات (تسويقي أو تصفّح). يُخفى تلقائيًا إن كانت المجموعة فارغة —
    يجعل أقسام النمو (related/cross-sell/bundles…) نقاط امتداد جاهزة بلا فوضى بصرية.

    عند تمرير recoType/placement يصبح قسم توصيات مُتتبَّعًا (Phase 6 / ADR-045):
    يحمل سمات بيانات يلتقطها JS المتجر لإطلاق أحداث الظهور/النقر (بلا تكرار).
--}}
@if ($items === null || $items->isNotEmpty())
    <section class="py-4"
        @if ($recoType)
            data-reco-section
            data-reco-type="{{ $recoType }}"
            data-reco-placement="{{ $placement }}"
            @if ($source) data-reco-source="{{ $source }}" @endif
        @endif
        {{ $attributes }}>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-900">{{ $title }}</h2>
            @if ($viewAll)
                <a href="{{ $viewAll }}" class="text-sm text-emerald-600 hover:underline">{{ __('storefront.view_all') }}</a>
            @endif
        </div>

        @if ($items !== null)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($items as $item)
                    @if ($recoType)
                        <div data-reco-product="{{ $item->id }}" data-reco-src="{{ $item->recommendation_source ?? 'rule' }}">
                            <x-storefront.product-card :product="$item" />
                        </div>
                    @else
                        <x-storefront.product-card :product="$item" />
                    @endif
                @endforeach
            </div>
        @else
            {{ $slot }}
        @endif
    </section>
@endif
