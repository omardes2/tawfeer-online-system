@props(['product'])
@inject('sf', 'App\Modules\Store\Services\StorefrontService')

@php
    $variant = $product->defaultVariant;
    $price = $sf->sellingPrice($product);
    $regular = $sf->regularPrice($product);
    $onSale = $sf->onSale($product);
    $available = $sf->availableQty($product);
    $inStock = $available > 1e-9;
    $img = $product->primaryImage;
    $discount = $onSale && $regular > 0 ? (int) round((1 - $price / $regular) * 100) : 0;
@endphp

<article class="group bg-white rounded-xl border border-gray-200 overflow-hidden flex flex-col hover:shadow-md transition-shadow"
         data-product="{{ $product->uuid }}">
    <a href="{{ route('storefront.product', $product->slug) }}" class="block relative aspect-square bg-gray-100 overflow-hidden">
        @if ($img)
            <img src="{{ $img->url() }}" alt="{{ $img->alt ?: $product->name }}" loading="lazy"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
        @else
            <div class="w-full h-full flex items-center justify-center text-gray-300">
                <svg class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 3.75h16.5v16.5H3.75V3.75Z"/></svg>
            </div>
        @endif

        {{-- شارة العرض (من سعر الكتالوج — حقيقة كتالوجية، لا محرّك نمو) --}}
        @if ($discount > 0)
            <span class="absolute top-2 start-2 bg-rose-600 text-white text-xs font-bold px-2 py-0.5 rounded">
                {{ $discount }}% {{ __('storefront.off') }}
            </span>
        @endif
        {{-- نقطة امتداد: شارات ترويجية من محرّك النمو مستقبلًا (badge slot) --}}

        @unless ($inStock)
            <span class="absolute inset-x-0 bottom-0 bg-gray-900/70 text-white text-xs text-center py-1">
                {{ __('storefront.out_of_stock') }}
            </span>
        @endunless
    </a>

    <div class="p-3 flex flex-col gap-2 flex-1">
        @if ($product->brand)
            <span class="text-xs text-gray-400">{{ $product->brand->name }}</span>
        @endif
        <a href="{{ route('storefront.product', $product->slug) }}"
           class="text-sm font-medium text-gray-800 hover:text-emerald-600 line-clamp-2 min-h-10">
            <x-storefront.name :product="$product" />
        </a>

        <div class="mt-auto flex items-end justify-between gap-2">
            <div class="flex flex-col">
                <span class="text-emerald-700 font-bold">{{ number_format($price, 2) }} {{ __('storefront.currency') }}</span>
                @if ($onSale)
                    <span class="text-xs text-gray-400 line-through">{{ number_format($regular, 2) }} {{ __('storefront.currency') }}</span>
                @endif
            </div>

            @if ($variant && $inStock)
                <button type="button"
                        x-data="{ done: false }"
                        @click="await $store.cart.add('{{ $variant->uuid }}', 1); done = true; setTimeout(() => done = false, 1500)"
                        class="shrink-0 inline-flex items-center gap-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                        :aria-label="'{{ __('storefront.add_to_cart') }}'">
                    <span x-show="!done">{{ __('storefront.add_to_cart') }}</span>
                    <span x-show="done" x-cloak>✓ {{ __('storefront.added') }}</span>
                </button>
            @else
                <button type="button" disabled
                        class="shrink-0 rounded-lg bg-gray-200 text-gray-400 text-xs font-medium px-3 py-2 cursor-not-allowed">
                    {{ __('storefront.out_of_stock') }}
                </button>
            @endif
        </div>
    </div>
</article>
