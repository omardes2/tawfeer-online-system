@php
    $q = $filters['q'] ?? null;
    $activeCategory = $category ?? null;
    $activeBrand = $brand ?? null;

    if ($q) {
        $pageEvent = ['name' => 'SearchPerformed', 'payload' => ['q' => $q, 'results' => $products->total()]];
    } elseif ($activeCategory) {
        $pageEvent = ['name' => 'CategoryViewed', 'payload' => ['category' => $activeCategory->slug]];
    } else {
        $pageEvent = ['name' => 'ProductListViewed', 'payload' => []];
    }

    // خيارات الترتيب — المدعومة في `StorefrontService::applySort` فقط.
    $sortOptions = [
        'newest' => __('storefront.sort_newest'),
        'price_asc' => __('storefront.sort_price_asc'),
        'price_desc' => __('storefront.sort_price_desc'),
        'name' => __('storefront.sort_name'),
    ];
    $currentSort = $filters['sort'] ?? 'newest';

    // الفلاتر المطبَّقة — تُعرض كرقائق قابلة للإزالة.
    $chips = [];
    if ($activeCategory) {
        $chips[] = ['label' => $activeCategory->name, 'remove' => array_merge($filters, ['category' => null])];
    } elseif (! empty($filters['category'])) {
        $chips[] = ['label' => $filters['category'], 'remove' => array_merge($filters, ['category' => null])];
    }
    if ($activeBrand) {
        $chips[] = ['label' => $activeBrand->name, 'remove' => array_merge($filters, ['brand' => null])];
    } elseif (! empty($filters['brand'])) {
        $chips[] = ['label' => $filters['brand'], 'remove' => array_merge($filters, ['brand' => null])];
    }
    if (($filters['min'] ?? '') !== '' || ($filters['max'] ?? '') !== '') {
        $chips[] = [
            'label' => trim(($filters['min'] ?? '0').' – '.($filters['max'] ?? '∞').' '.__('storefront.currency')),
            'remove' => array_merge($filters, ['min' => null, 'max' => null]),
        ];
    }

    $breadcrumbs = [__('storefront.home') => route('storefront.home')];
    if ($activeCategory) {
        $breadcrumbs[__('storefront.categories')] = route('storefront.categories');
    }
    $breadcrumbs[$heading] = null;
@endphp

<x-storefront.layout :title="$heading" :page-event="$pageEvent">

    <x-storefront.page-header :title="$heading" :breadcrumbs="$breadcrumbs"
        :subtitle="trans_choice('storefront.products_count', $products->total(), ['count' => $products->total()])">

        {{-- الترتيب: نموذج يُرسَل عند التغيير، ويحمل بقية المعاملات كي لا تضيع --}}
        <form action="{{ route('storefront.shop') }}" method="GET" class="shrink-0">
            @foreach (['category', 'brand', 'q', 'min', 'max'] as $k)
                @if (($filters[$k] ?? '') !== '') <input type="hidden" name="{{ $k }}" value="{{ $filters[$k] }}"> @endif
            @endforeach
            <label for="sort" class="sr-only">{{ __('storefront.sort') }}</label>
            <select id="sort" name="sort" onchange="this.form.submit()" class="sf-select !py-2.5 min-h-10">
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected($currentSort === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>

        {{-- الجوّال: زرّ يفتح درج التصفية --}}
        {{-- `x-data` المجرّدة تُنشئ سياق Alpine؛ بدونها لا يُسجَّل `@click` أصلًا
             ولا يُطلق الحدث، فيبقى الدرج مغلقًا. --}}
        <button type="button" x-data @click="$dispatch('open-filters')"
                class="sf-btn-outline lg:hidden min-h-10 !px-3">
            <x-storefront.icon name="tag" class="w-4 h-4" />
            {{ __('storefront.filters') }}
            @if (count($chips))
                <span class="grid place-items-center min-w-5 h-5 px-1 rounded-full bg-brand-600 text-white text-[11px] font-bold">{{ count($chips) }}</span>
            @endif
        </button>
    </x-storefront.page-header>

    {{-- رقائق الفلاتر المطبَّقة --}}
    @if (count($chips))
        <div class="flex items-center gap-2 flex-wrap mb-4">
            @foreach ($chips as $chip)
                <a href="{{ route('storefront.shop', array_filter($chip['remove'], fn ($v) => $v !== null && $v !== '')) }}"
                   class="sf-badge sf-badge-soft min-h-8 ps-3 pe-2 gap-1.5 hover:bg-brand-100 transition-colors"
                   aria-label="{{ __('storefront.remove_filter', ['name' => $chip['label']]) }}">
                    {{ $chip['label'] }}
                    <x-storefront.icon name="close" class="w-3.5 h-3.5" />
                </a>
            @endforeach
            <a href="{{ route('storefront.shop', array_filter(['q' => $q, 'sort' => $filters['sort'] ?? null])) }}"
               class="text-xs font-semibold text-[color:var(--sf-text-soft)] hover:text-brand-600 py-2 px-1 transition-colors">
                {{ __('storefront.clear_all') }}
            </a>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
        {{-- الشريط الجانبي (حواسيب) --}}
        <aside class="hidden lg:block">
            <div class="sf-card sf-card-pad sticky top-[7.5rem]">
                <h2 class="font-bold mb-4 text-[color:var(--sf-text)]">{{ __('storefront.filters') }}</h2>
                <x-storefront.filters :filters="$filters" :categories="$categories" :brands="$brands"
                    :action="route('storefront.shop')" uid="d" />
            </div>
        </aside>

        {{-- الشبكة --}}
        <div class="min-w-0">
            @if ($products->isEmpty())
                <x-storefront.empty-state
                    :icon="$q ? 'search' : 'box'"
                    :title="$q ? __('storefront.no_search_results', ['q' => $q]) : __('storefront.no_products')"
                    :description="__('storefront.no_products_hint')"
                    :action="count($chips) || $q ? route('storefront.shop') : null"
                    :action-label="count($chips) || $q ? __('storefront.clear_all') : null" />
            @else
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3 sm:gap-4">
                    @foreach ($products as $product)
                        <x-storefront.product-card :product="$product" />
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <nav class="mt-8" aria-label="{{ __('storefront.pagination') }}">{{ $products->links('vendor.pagination.storefront') }}</nav>
                @endif
            @endif
        </div>
    </div>

    {{-- درج التصفية (جوّال) — نفس مكوّن الفلاتر، بلا تكرار للحقول --}}
    <div x-data="{ open: false }" @open-filters.window="open = true"
         @keydown.escape.window="open = false" class="lg:hidden">
        <div x-show="open" x-cloak x-transition.opacity @click="open = false"
             class="fixed inset-0 z-40 sf-scrim"></div>

        <div x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] flex flex-col bg-white rounded-t-2xl shadow-xl sf-safe-bottom"
             role="dialog" aria-modal="true" :aria-label="'{{ __('storefront.filters') }}'">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-[color:var(--sf-border)]">
                <h2 class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.filters') }}</h2>
                <button type="button" @click="open = false"
                        class="grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text-soft)] hover:bg-[color:var(--sf-bg)]"
                        aria-label="{{ __('storefront.close_menu') }}">
                    <x-storefront.icon name="close" class="w-6 h-6" />
                </button>
            </div>
            <div class="overflow-y-auto p-4">
                <x-storefront.filters :filters="$filters" :categories="$categories" :brands="$brands"
                    :action="route('storefront.shop')" uid="m" />
            </div>
        </div>
    </div>

    {{-- بيانات مهيكلة: قائمة عناصر --}}
    @push('structured-data')
        <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $heading,
            'numberOfItems' => $products->total(),
            'itemListElement' => $products->getCollection()->values()->map(fn ($p, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => route('storefront.product', $p->slug),
                'name' => $p->name,
            ])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush
</x-storefront.layout>
