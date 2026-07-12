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
@endphp

<x-storefront.layout :title="$heading" :page-event="$pageEvent">
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $heading }}</h1>
            <p class="text-sm text-gray-500">{{ __('storefront.results_count') }}: {{ $products->total() }}</p>
        </div>

        {{-- الترتيب --}}
        <form action="{{ route('storefront.shop') }}" method="GET" class="shrink-0">
            @foreach (['category', 'brand', 'q', 'min', 'max'] as $k)
                @if (! empty($filters[$k])) <input type="hidden" name="{{ $k }}" value="{{ $filters[$k] }}"> @endif
            @endforeach
            <label for="sort" class="sr-only">{{ __('storefront.sort') }}</label>
            <select id="sort" name="sort" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm py-2 focus:border-emerald-500 focus:ring-emerald-500">
                <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>{{ __('storefront.sort_newest') }}</option>
                <option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>{{ __('storefront.sort_price_asc') }}</option>
                <option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>{{ __('storefront.sort_price_desc') }}</option>
                <option value="name" @selected(($filters['sort'] ?? '') === 'name')>{{ __('storefront.sort_name') }}</option>
            </select>
        </form>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- التصفية --}}
        <aside class="lg:col-span-1" x-data="{ open: false }">
            <button type="button" @click="open = !open"
                    class="lg:hidden w-full mb-3 inline-flex items-center justify-between rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm">
                {{ __('storefront.filters') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>

            <form action="{{ route('storefront.shop') }}" method="GET"
                  class="bg-white rounded-xl border border-gray-200 p-4 space-y-4"
                  :class="{ 'hidden lg:block': !open }">
                @if ($q) <input type="hidden" name="q" value="{{ $q }}"> @endif
                @if (! empty($filters['sort'])) <input type="hidden" name="sort" value="{{ $filters['sort'] }}"> @endif

                <div>
                    <label for="f-category" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.category') }}</label>
                    <select id="f-category" name="category" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('storefront.all') }}</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->slug }}" @selected(($filters['category'] ?? '') === $c->slug)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="f-brand" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.brand') }}</label>
                    <select id="f-brand" name="brand" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('storefront.all') }}</option>
                        @foreach ($brands as $b)
                            <option value="{{ $b->slug }}" @selected(($filters['brand'] ?? '') === $b->slug)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="f-min" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.min_price') }}</label>
                        <input id="f-min" type="number" min="0" step="0.01" name="min" value="{{ $filters['min'] ?? '' }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="f-max" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.max_price') }}</label>
                        <input id="f-max" type="number" min="0" step="0.01" name="max" value="{{ $filters['max'] ?? '' }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <button type="submit" class="flex-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium py-2">{{ __('storefront.apply') }}</button>
                    <a href="{{ route('storefront.shop') }}" class="rounded-lg border border-gray-300 text-sm text-gray-600 px-3 py-2 hover:bg-gray-50">{{ __('storefront.clear') }}</a>
                </div>
            </form>
        </aside>

        {{-- الشبكة --}}
        <div class="lg:col-span-3">
            @if ($products->isEmpty())
                <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5 12 3 3.75 7.5m16.5 0L12 12m8.25-4.5v9L12 21m0-9L3.75 7.5m0 0v9L12 21"/></svg>
                    <p class="text-gray-700 font-medium">{{ __('storefront.no_products') }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ __('storefront.no_products_hint') }}</p>
                    <a href="{{ route('storefront.shop') }}" class="inline-block mt-4 text-sm text-emerald-600 hover:underline">{{ __('storefront.back_to_shop') }}</a>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
                    @foreach ($products as $product)
                        <x-storefront.product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-6">{{ $products->links() }}</div>
            @endif
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
