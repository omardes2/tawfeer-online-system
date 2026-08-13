<x-storefront.account-layout :title="__('account.wishlist')" active="wishlist">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="sf-section-title">{{ __('account.wishlist') }}</h1>
        @if ($products->isNotEmpty())
            <span class="sf-badge sf-badge-soft">{{ __('account.items_count') }}: {{ number_format($products->count()) }}</span>
        @endif
    </div>

    @if ($products->isEmpty())
        <x-storefront.empty-state icon="heart"
            :title="__('account.no_wishlist')"
            :description="__('account.no_wishlist_hint')"
            :action="route('storefront.shop')"
            :action-label="__('storefront.shop')" />
    @else
        {{-- بطاقة المنتج القائمة كما هي، وزرّ الإزالة يُلفّ فوقها فلا يتغيّر عقدها. --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
            @foreach ($products as $product)
                <div class="relative">
                    <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}"
                          class="absolute top-2 end-2 z-10">
                        @csrf
                        <button type="submit"
                                class="grid place-items-center w-10 h-10 rounded-full bg-white/95 backdrop-blur
                                       text-[color:var(--sf-danger)] shadow-sm hover:bg-white transition-colors"
                                aria-label="{{ __('account.remove_from_wishlist') }}">
                            <x-storefront.icon name="heart" class="w-5 h-5" filled />
                        </button>
                    </form>
                    <x-storefront.product-card :product="$product" />
                </div>
            @endforeach
        </div>
    @endif
</x-storefront.account-layout>
