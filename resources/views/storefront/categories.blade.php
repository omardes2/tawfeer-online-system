<x-storefront.layout :title="__('storefront.categories')" :page-event="['name' => 'CategoryListViewed', 'payload' => []]">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('storefront.categories') }}</h1>

    @if ($categories->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500">
            {{ __('storefront.no_categories') }}
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
            @foreach ($categories as $category)
                <a href="{{ route('storefront.category', $category->slug) }}"
                   class="flex items-center gap-3 p-4 rounded-xl bg-white border border-gray-200 hover:border-emerald-300 hover:shadow-sm">
                    @if ($category->iconUrl())
                        <img src="{{ $category->iconUrl() }}" alt="{{ $category->name }}" class="h-11 w-11 shrink-0 rounded-full object-cover bg-emerald-50" />
                    @else
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 font-bold text-lg">
                            {{ mb_substr($category->name, 0, 1) }}
                        </span>
                    @endif
                    <span class="font-medium text-gray-800 line-clamp-2">{{ $category->name }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-storefront.layout>
