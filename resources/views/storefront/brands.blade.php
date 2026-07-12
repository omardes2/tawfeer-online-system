<x-storefront.layout :title="__('storefront.brands')" :page-event="['name' => 'BrandListViewed', 'payload' => []]">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('storefront.brands') }}</h1>

    @if ($brands->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500">
            {{ __('storefront.no_brands') }}
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4">
            @foreach ($brands as $brand)
                <a href="{{ route('storefront.brand', $brand->slug) }}"
                   class="flex flex-col items-center justify-center gap-2 p-5 rounded-xl bg-white border border-gray-200 hover:border-emerald-300 hover:shadow-sm text-center">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-600 font-bold text-lg">
                        {{ mb_substr($brand->name, 0, 1) }}
                    </span>
                    <span class="font-medium text-gray-800 text-sm line-clamp-1">{{ $brand->name }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-storefront.layout>
