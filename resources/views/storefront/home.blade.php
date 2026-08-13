<x-storefront.layout :page-event="['name' => 'HomeViewed', 'payload' => []]">

    {{-- ══════════ البطل ══════════ --}}
    <section class="relative overflow-hidden rounded-2xl bg-brand-600 text-white
                    px-6 py-10 sm:px-10 sm:py-14 lg:py-16">
        {{-- زخرفة خفيفة: دوائر شفّافة بدل صور ثقيلة --}}
        {{-- زخرفة واحدة خفيفة: الأداء أهم من التزيين --}}
        <span aria-hidden="true" class="absolute -bottom-28 -end-16 w-72 h-72 rounded-full bg-white/[.06]"></span>

        <div class="relative max-w-xl">
            <span class="sf-badge sf-badge-new mb-4">
                <x-storefront.icon name="percent" class="w-3.5 h-3.5" />
                {{ __('storefront.offers') }}
            </span>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-tight">
                {{ __('storefront.hero_title') }}
            </h1>
            <p class="mt-3 text-base sm:text-lg text-white/85">{{ __('storefront.hero_subtitle') }}</p>
            <a href="{{ route('storefront.shop') }}" class="sf-btn-accent sf-btn-lg mt-7">
                {{ __('storefront.shop_now') }}
                <x-storefront.icon name="chevron-left" class="w-5 h-5 rtl:rotate-0 ltr:rotate-180" />
            </a>
        </div>
    </section>

    {{-- ══════════ الأقسام ══════════ --}}
    @if ($categories->isNotEmpty())
        <section class="py-6">
            <div class="flex items-end justify-between gap-x-4 gap-y-1 flex-wrap mb-4">
                <h2 class="sf-section-title">{{ __('storefront.browse_categories') }}</h2>
                <a href="{{ route('storefront.categories') }}" class="sf-section-link inline-flex items-center gap-1">
                    {{ __('storefront.view_all') }}
                    <x-storefront.icon name="chevron-left" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
                </a>
            </div>

            {{-- جوّال: تمرير أفقي بلمسة الإصبع. حواسيب: صفّ كامل. --}}
            <div class="sf-scroll-x sm:grid sm:grid-cols-4 lg:grid-cols-8 sm:gap-3 -mx-4 px-4 sm:mx-0 sm:px-0">
                @foreach ($categories as $category)
                    <x-storefront.category-card :category="$category" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- ══════════ عروض اليوم ══════════ --}}
    <x-storefront.section
        :title="__('storefront.todays_offers')"
        :items="$featured"
        :view-all="route('storefront.shop')" />

    {{-- ══════════ لافتة ترويجية ══════════ --}}
    <section class="my-4 rounded-2xl bg-gold-300 text-[color:var(--sf-text)] px-6 py-7 sm:px-9 sm:py-8
                    flex flex-col sm:flex-row items-center justify-between gap-5 text-center sm:text-start">
        <div class="flex items-center gap-4">
            <span class="hidden sm:grid place-items-center w-14 h-14 rounded-2xl bg-white/60 text-brand-700 shrink-0">
                <x-storefront.icon name="truck" class="w-7 h-7" />
            </span>
            <div>
                <p class="text-lg sm:text-xl font-extrabold">{{ __('storefront.promo_title') }}</p>
                <p class="text-sm mt-0.5 opacity-80">{{ __('storefront.promo_subtitle') }}</p>
            </div>
        </div>
        <a href="{{ route('storefront.shop') }}" class="sf-btn-primary shrink-0">{{ __('storefront.shop_now') }}</a>
    </section>

    {{-- ══════════ الأكثر مبيعًا ══════════ --}}
    <x-storefront.section
        :title="__('storefront.best_sellers')"
        :items="$bestSellers"
        :view-all="route('storefront.shop')" />

    {{-- ══════════ وصل حديثًا ══════════ --}}
    <x-storefront.section
        :title="__('storefront.new_arrivals')"
        :items="$newArrivals"
        :view-all="route('storefront.shop', ['sort' => 'newest'])" />

    {{-- ══════════ شريط الثقة ══════════ --}}
    <section class="my-6 grid grid-cols-2 lg:grid-cols-4 gap-3" id="help">
        @foreach ([
            ['icon' => 'truck', 'label' => __('storefront.trust_delivery')],
            ['icon' => 'shield', 'label' => __('storefront.trust_cod')],
            ['icon' => 'headset', 'label' => __('storefront.trust_support')],
            ['icon' => 'check-circle', 'label' => __('storefront.trust_quality')],
        ] as $item)
            <div class="sf-card flex items-center gap-3 p-4">
                <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand-50 text-brand-600 shrink-0">
                    <x-storefront.icon :name="$item['icon']" class="w-5 h-5" />
                </span>
                <span class="min-w-0 text-[13px] font-semibold leading-snug">{{ $item['label'] }}</span>
            </div>
        @endforeach
    </section>

    {{-- ══════════ العلامات التجارية ══════════ --}}
    @if ($brands->isNotEmpty())
        <section class="py-4">
            <div class="flex items-end justify-between gap-x-4 gap-y-1 flex-wrap mb-4">
                <h2 class="sf-section-title">{{ __('storefront.shop_by_brand') }}</h2>
                <a href="{{ route('storefront.brands') }}" class="sf-section-link inline-flex items-center gap-1">
                    {{ __('storefront.view_all') }}
                    <x-storefront.icon name="chevron-left" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
                </a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($brands as $brand)
                    <a href="{{ route('storefront.brand', $brand->slug) }}"
                       class="sf-card sf-card-hover inline-flex items-center min-h-10 px-4 py-2 text-sm font-semibold text-[color:var(--sf-text)] hover:text-brand-600 transition-colors">
                        {{ $brand->name }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-storefront.layout>
