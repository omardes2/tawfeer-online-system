<x-storefront.account-layout :title="__('account.wishlist')" active="wishlist">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('account.wishlist') }}</h1>

    @if ($products->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
            <p class="text-gray-700 font-medium">{{ __('account.no_wishlist') }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ __('account.no_wishlist_hint') }}</p>
            <a href="{{ route('storefront.shop') }}" class="inline-block mt-4 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('storefront.shop') }}</a>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
            @foreach ($products as $product)
                <div class="relative">
                    <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}" class="absolute top-2 end-2 z-10">
                        @csrf
                        <button type="submit" class="h-8 w-8 inline-flex items-center justify-center rounded-full bg-white/90 text-rose-500 shadow hover:bg-white" aria-label="{{ __('account.wishlist_removed') }}">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.2C.6 8.9 2 5.5 5.2 5.5c1.9 0 3.2 1.1 3.8 2.2.6-1.1 1.9-2.2 3.8-2.2 3.2 0 4.6 3.4 3.2 6.3C19.5 16.4 12 21 12 21Z"/></svg>
                        </button>
                    </form>
                    <x-storefront.product-card :product="$product" />
                </div>
            @endforeach
        </div>
    @endif
</x-storefront.account-layout>
