@props(['product', 'wishlisted' => false])
@inject('sf', 'App\Modules\Store\Services\StorefrontService')

{{--
    بطاقة المنتج.

    زرّ «أضف للسلة» يتحوّل إلى محدّد كمية «− 1 +» بعد الإضافة بلا إعادة تحميل،
    ويقرأ الكمية من مخزن السلة نفسه (`$store.cart`) لا من حالة محلّية — ففتح
    الصفحة على منتج موجود في السلة يعرض كميته مباشرة، والتغيير من أي مكان
    ينعكس على البطاقة.

    منطق السلة نفسه لم يُمسّ: نفس نداءات add/setQty/remove على واجهة `/api/v1/store`.
--}}
@php
    $variant = $product->defaultVariant;
    $price = $sf->sellingPrice($product);
    $regular = $sf->regularPrice($product);
    $onSale = $sf->onSale($product);
    $available = $sf->availableQty($product);
    $inStock = $available > 1e-9;
    $img = $product->primaryImage;
    $discount = $onSale && $regular > 0 ? (int) round((1 - $price / $regular) * 100) : 0;
    $url = route('storefront.product', $product->slug);
@endphp

<article class="sf-card sf-card-hover group flex flex-col overflow-hidden h-full" data-product="{{ $product->uuid }}">
    {{-- الصورة --}}
    <div class="relative aspect-square bg-[color:var(--sf-bg)] overflow-hidden">
        {{-- مكرّر لرابط الاسم أدناه: يُخرَج من ترتيب التركيز ومن قارئ الشاشة معًا --}}
        <a href="{{ $url }}" class="block w-full h-full" tabindex="-1" aria-hidden="true">
            @if ($img)
                <img src="{{ $img->url() }}" alt="{{ $img->alt ?: $product->name }}"
                     loading="lazy" decoding="async"
                     class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
            @else
                <span class="w-full h-full grid place-items-center text-gray-300">
                    <x-storefront.icon name="image" class="w-14 h-14" />
                </span>
            @endif
        </a>

        {{-- شارة الخصم --}}
        @if ($discount > 0)
            <span class="sf-badge sf-badge-discount absolute top-2.5 start-2.5">
                {{ __('storefront.off') }} {{ $discount }}%
            </span>
        @endif

        {{-- المفضّلة: للضيف يقود لتسجيل الدخول، وللمُسجَّل يبدّل الحالة --}}
        @auth
            <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}"
                  class="absolute top-2 end-2">
                @csrf
                <button type="submit"
                        class="grid place-items-center w-10 h-10 rounded-full bg-white/95 backdrop-blur-sm shadow-sm
                               transition-colors {{ $wishlisted ? 'text-[color:var(--sf-danger)]' : 'text-[color:var(--sf-text-soft)] hover:text-[color:var(--sf-danger)]' }}"
                        aria-label="{{ __('storefront.add_to_favorites') }}" title="{{ __('storefront.add_to_favorites') }}">
                    <x-storefront.icon name="heart" class="w-[18px] h-[18px]" :filled="$wishlisted" />
                </button>
            </form>
        @else
            <a href="{{ route('account.login') }}"
               class="absolute top-2 end-2 grid place-items-center w-10 h-10 rounded-full bg-white/95 backdrop-blur-sm shadow-sm
                      text-[color:var(--sf-text-soft)] hover:text-[color:var(--sf-danger)] transition-colors"
               aria-label="{{ __('storefront.add_to_favorites') }}" title="{{ __('storefront.add_to_favorites') }}">
                <x-storefront.icon name="heart" class="w-[18px] h-[18px]" />
            </a>
        @endauth

        @unless ($inStock)
            <span class="sf-oos">
                {{ __('storefront.out_of_stock') }}
            </span>
        @endunless
    </div>

    {{-- المحتوى --}}
    <div class="p-3 flex flex-col gap-1.5 flex-1">
        @if ($product->brand)
            <span class="text-[11px] font-semibold text-[color:var(--sf-text-soft)] line-clamp-1">{{ $product->brand->name }}</span>
        @endif

        <a href="{{ $url }}" class="text-[13px] sm:text-sm font-semibold leading-snug line-clamp-2 min-h-10
                                    text-[color:var(--sf-text)] hover:text-brand-600 transition-colors">
            <x-storefront.name :product="$product" />
        </a>

        <x-storefront.price :price="$price" :regular="$onSale ? $regular : null" class="mt-1" />

        {{-- الإضافة للسلة / تعديل الكمية --}}
        <div class="mt-auto pt-2">
            @if ($variant && $inStock)
                <div x-data="{
                        variant: '{{ $variant->uuid }}',
                        max: {{ (int) floor($available) }},
                        busy: false,
                        get qty() {
                            // الواجهة تُعيد الكمية كنصّ عشري ('1.000')، فـ`qty + 1` بلا تحويل
                            // يُنتج دمجًا نصّيًا لا جمعًا، و`qty === 1` لا يتحقّق أبدًا.
                            const item = $store.cart.items.find(i => i.variant_id === this.variant);
                            return item ? Math.round(Number(item.qty)) : 0;
                        },
                        async add() { this.busy = true; await $store.cart.add(this.variant, 1); this.busy = false; },
                        async set(n) {
                            this.busy = true;
                            n <= 0 ? await $store.cart.remove(this.variant) : await $store.cart.setQty(this.variant, n);
                            this.busy = false;
                        },
                     }">
                    {{-- بلا كمية: زرّ الإضافة --}}
                    <button type="button" x-show="qty === 0" @click="add()" :disabled="busy"
                            class="sf-btn-primary sf-btn-block whitespace-nowrap min-h-10
                                   !px-2 !py-2 text-[12px] sm:text-[13px] gap-1.5">
                        {{-- الأيقونة تختفي على أضيق الشاشات كي يبقى النصّ في سطر واحد --}}
                        <x-storefront.icon name="cart" class="w-4 h-4 hidden min-[360px]:block" />
                        {{ __('storefront.add_to_cart') }}
                    </button>

                    {{-- بعد الإضافة: محدّد الكمية --}}
                    <div x-show="qty > 0" x-cloak class="sf-qty w-full justify-between">
                        <button type="button" @click="set(qty - 1)" :disabled="busy"
                                :aria-label="qty === 1 ? '{{ __('storefront.remove') }}' : '−'">
                            <span x-show="qty > 1" aria-hidden="true">−</span>
                            <span x-show="qty === 1" x-cloak aria-hidden="true">
                                <x-storefront.icon name="trash" class="w-4 h-4" />
                            </span>
                        </button>
                        <output x-text="qty" aria-live="polite"></output>
                        <button type="button" @click="set(qty + 1)" :disabled="busy || qty >= max" aria-label="+">+</button>
                    </div>
                </div>
            @else
                <button type="button" disabled class="sf-btn sf-btn-sm sf-btn-block bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)]">
                    {{ __('storefront.out_of_stock') }}
                </button>
            @endif
        </div>
    </div>
</article>
