<x-storefront.layout :page-event="['name' => 'HomeViewed', 'payload' => []]">
    {{-- بطل الصفحة --}}
    <section class="rounded-2xl bg-gradient-to-l from-emerald-600 to-emerald-500 text-white p-6 sm:p-10 mb-6">
        <div class="max-w-2xl">
            <h1 class="text-2xl sm:text-4xl font-bold mb-2">{{ __('storefront.site_name') }}</h1>
            <p class="text-emerald-50 mb-5">{{ __('storefront.tagline') }}</p>
            <a href="{{ route('storefront.shop') }}"
               class="inline-flex items-center rounded-lg bg-white text-emerald-700 font-semibold px-5 py-2.5 hover:bg-emerald-50">
                {{ __('storefront.shop') }}
            </a>
        </div>
    </section>

    {{-- تسوّق حسب الفئة --}}
    @if ($categories->isNotEmpty())
        <section class="py-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-900">{{ __('storefront.shop_by_category') }}</h2>
                <a href="{{ route('storefront.categories') }}" class="text-sm text-emerald-600 hover:underline">{{ __('storefront.view_all') }}</a>
            </div>
            <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                @foreach ($categories as $category)
                    <a href="{{ route('storefront.category', $category->slug) }}"
                       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-white border border-gray-200 hover:border-emerald-300 hover:shadow-sm text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 font-bold">
                            {{ mb_substr($category->name, 0, 1) }}
                        </span>
                        <span class="text-xs text-gray-700 line-clamp-1">{{ $category->name }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- مميّز (كتالوج) --}}
    <x-storefront.section :title="__('storefront.featured')" :items="$featured" :view-all="route('storefront.shop')" />

    {{-- وصل حديثًا (كتالوج) --}}
    <x-storefront.section :title="__('storefront.new_arrivals')" :items="$newArrivals" :view-all="route('storefront.shop', ['sort' => 'newest'])" />

    {{-- نقطة امتداد: الأكثر مبيعًا / مقترح لك (تُملأ من محرّك النمو مستقبلًا — ADR-032) --}}

    {{-- العلامات التجارية --}}
    @if ($brands->isNotEmpty())
        <section class="py-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-900">{{ __('storefront.shop_by_brand') }}</h2>
                <a href="{{ route('storefront.brands') }}" class="text-sm text-emerald-600 hover:underline">{{ __('storefront.view_all') }}</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($brands as $brand)
                    <a href="{{ route('storefront.brand', $brand->slug) }}"
                       class="px-4 py-2 rounded-full bg-white border border-gray-200 text-sm text-gray-700 hover:border-emerald-300">
                        {{ $brand->name }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-storefront.layout>
