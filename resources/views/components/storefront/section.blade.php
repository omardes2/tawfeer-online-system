@props(['title', 'items' => null, 'viewAll' => null, 'recoType' => null, 'placement' => null, 'source' => null, 'subtitle' => null])

{{--
    قسم عرض منتجات (تسويقي أو تصفّح). يُخفى تلقائيًا إن كانت المجموعة فارغة —
    يجعل أقسام النمو (related/cross-sell/bundles…) نقاط امتداد جاهزة بلا فوضى بصرية.

    عند تمرير recoType/placement يصبح قسم توصيات مُتتبَّعًا (Phase 6 / ADR-045):
    يحمل سمات بيانات يلتقطها JS المتجر لإطلاق أحداث الظهور/النقر (بلا تكرار).
--}}
@if ($items === null || $items->isNotEmpty())
    <section class="py-4 sm:py-6"
        @if ($recoType)
            data-reco-section
            data-reco-type="{{ $recoType }}"
            data-reco-placement="{{ $placement }}"
            @if ($source) data-reco-source="{{ $source }}" @endif
        @endif
        {{ $attributes }}>
        <div class="flex items-end justify-between gap-x-4 gap-y-1 flex-wrap mb-4">
            <div class="min-w-0">
                <h2 class="sf-section-title">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-[color:var(--sf-text-soft)]">{{ $subtitle }}</p>
                @endif
            </div>
            @if ($viewAll)
                <a href="{{ $viewAll }}" class="sf-section-link inline-flex items-center gap-1">
                    {{ __('storefront.view_all') }}
                    <x-storefront.icon name="chevron-left" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
                </a>
            @endif
        </div>

        @if ($items !== null)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-4">
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
