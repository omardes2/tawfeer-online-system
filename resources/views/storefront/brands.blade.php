<x-storefront.layout :title="__('storefront.brands')" :page-event="['name' => 'BrandListViewed', 'payload' => []]">

    <x-storefront.page-header :title="__('storefront.brands')"
        :subtitle="$brands->isEmpty() ? null : trans_choice('storefront.brands_count', $brands->count(), ['count' => $brands->count()])"
        :breadcrumbs="[__('storefront.home') => route('storefront.home'), __('storefront.brands') => null]" />

    @if ($brands->isEmpty())
        <x-storefront.empty-state icon="tag"
            :title="__('storefront.no_brands')"
            :action="route('storefront.shop')"
            :action-label="__('storefront.shop')" />
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4">
            @foreach ($brands as $brand)
                <a href="{{ route('storefront.brand', $brand->slug) }}"
                   class="sf-card sf-card-hover flex flex-col items-center justify-center gap-2.5 p-5 text-center
                          border-transparent hover:border-brand-300 transition-colors">
                    <span class="grid place-items-center h-14 w-14 rounded-full bg-brand-50 text-brand-600 font-extrabold text-xl">
                        {{ mb_substr($brand->name, 0, 1) }}
                    </span>
                    <span class="text-sm font-semibold line-clamp-2 text-[color:var(--sf-text)]">{{ $brand->name }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-storefront.layout>
