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
    // منتج بمقاسات/ألوان: لا يُضاف من البطاقة لأن المتغيّر يتبع اختيار الزبون —
    // والإضافة بالمتغيّر الافتراضي كانت تفشل حين يكون مخزونه على المقاسات.
    $hasOptions = $product->relationLoaded('variants')
        && $product->variants->contains(fn ($v) => $v->is_active && $v->attributeValues->isNotEmpty());
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
                {{-- width/height: نسبة أبعاد صريحة تحجز مساحة الصورة قبل تحميلها فلا يقفز التخطيط --}}
                <img src="{{ $img->url() }}" alt="{{ $img->alt ?: $product->name }}"
                     width="600" height="600" loading="lazy" decoding="async"
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
            @if ($hasOptions && $inStock)
                <a href="{{ $url }}" class="sf-btn-outline sf-btn-sm sf-btn-block min-h-10 whitespace-nowrap">
                    {{ __('storefront.choose_options') }}
                </a>
            @elseif ($variant && $inStock)
                {{-- منطق السلة في مكوّن واحد يتقاسمه هذا الكرت وصفحة المنتج --}}
                <x-storefront.add-to-cart :variant="$variant->uuid" :max="(int) floor($available)" />
            @else
                {{-- min-h-10: بنفس ارتفاع زرّ «أضف إلى السلة» وإلّا تفاوتت ارتفاعات البطاقات في الشبكة --}}
                <button type="button" disabled
                        class="sf-btn sf-btn-sm sf-btn-block min-h-10 bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)]">
                    {{ __('storefront.out_of_stock') }}
                </button>
            @endif
        </div>
    </div>
</article>
