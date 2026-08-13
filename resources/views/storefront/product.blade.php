@inject('sf', 'App\Modules\Store\Services\StorefrontService')
@php
    $variant = $product->defaultVariant;
    $price = $sf->sellingPrice($product);
    $regular = $sf->regularPrice($product);
    $onSale = $sf->onSale($product);
    $available = $sf->availableQty($product);
    $inStock = $available > 1e-9;
    $images = $product->images;
    $primary = $product->primaryImage ?: $images->first();
    $locale = app()->getLocale();
    $displayName = $locale === 'en' && filled($product->name_en) ? $product->name_en : $product->name;
    $desc = $locale === 'en' && filled($product->description_en) ? $product->description_en : $product->description;
    $shortDesc = $locale === 'en' && filled($product->short_description_en) ? $product->short_description_en : $product->short_description;
    $metaDesc = $product->meta_description ?: $shortDesc ?: \Illuminate\Support\Str::limit(strip_tags((string) $desc), 155);
    $discount = $onSale && $regular > 0 ? (int) round((1 - $price / $regular) * 100) : 0;

    // نظام المتغيّرات الكاملة: متغيّرات الخيارات المفعّلة (مقاس/لون) — يختارها الزبون.
    $cart = app(\App\Modules\Store\Services\CartService::class);
    $optionVariants = $product->variants->filter(fn ($v) => $v->is_active && $v->attributeValues->isNotEmpty());
    $hasOptions = $optionVariants->isNotEmpty();
    $attrNames = $product->attributes->pluck('name', 'id');

    // مجموعات الخيارات (سمة → قيمها المستخدمة فعليًا في المتغيّرات).
    $optionGroups = [];
    foreach ($optionVariants as $ov) {
        foreach ($ov->attributeValues as $val) {
            $aid = (int) $val->attribute_id;
            $optionGroups[$aid]['id'] = $aid;
            $optionGroups[$aid]['name'] = $attrNames[$aid] ?? '';
            $optionGroups[$aid]['values'][$val->id] = [
                'id' => (int) $val->id,
                'label' => $val->label ?: $val->value,
                'color' => $val->color_hex,
            ];
        }
    }
    $optionGroups = array_map(function ($g) {
        $g['values'] = array_values($g['values']);
        return $g;
    }, array_values($optionGroups));

    // بيانات المتغيّرات لـ JS: التركيبة + السعر + التوفّر.
    $variantsJs = $optionVariants->map(function ($v) use ($cart) {
        $sell = $cart->sellingPrice($v);
        $regular = (float) $v->retail_price;
        $avail = $cart->availableQty($v);
        return [
            'uuid' => $v->uuid,
            'values' => $v->attributeValues->pluck('id')->map(fn ($i) => (int) $i)->values(),
            'price' => round($sell, 2),
            'regular' => round($regular, 2),
            'onSale' => $sell < $regular - 1e-9,
            'available' => $avail > 1e-9,
            'max' => (int) floor($avail),
        ];
    })->values();

    // المفضّلة: الحالة الحقيقية من الخدمة القائمة (لا حالة محلّية).
    $wishCustomer = auth()->check() ? \App\Modules\Crm\Models\Customer::where('user_id', auth()->id())->first() : null;
    $inWishlist = $wishCustomer && app(\App\Modules\Store\Services\WishlistService::class)->has($wishCustomer, $product);

    // صفوف المعلومات — ما هو موجود فعلًا فقط، بلا صفوف فارغة.
    $infoRows = array_filter([
        __('storefront.sku') => $product->sku,
        __('storefront.brand') => $product->brand?->name,
        __('storefront.category') => $product->category?->name,
        __('storefront.unit') => $product->unit?->name,
    ]);
@endphp

<x-storefront.layout
    :title="$product->meta_title ?: $displayName"
    :description="$metaDesc"
    :canonical="route('storefront.product', $product->slug)"
    :image="$primary?->url()"
    :page-event="['name' => 'ProductViewed', 'payload' => ['product' => $product->uuid, 'sku' => $product->sku]]">

    {{-- مسار التنقّل — مضغوط على الجوّال --}}
    <nav class="flex items-center gap-1.5 flex-wrap text-xs text-[color:var(--sf-text-soft)] mb-4"
         aria-label="{{ __('storefront.breadcrumb') }}">
        <a href="{{ route('storefront.home') }}" class="hover:text-brand-600 transition-colors">{{ __('storefront.home') }}</a>
        @if ($product->category)
            <x-storefront.icon name="chevron-left" class="w-3 h-3 opacity-50 ltr:rotate-180" />
            <a href="{{ route('storefront.category', $product->category->slug) }}"
               class="hover:text-brand-600 transition-colors">{{ $product->category->name }}</a>
        @endif
        {{-- اسم المنتج يظهر في المسار على الشاشات المتوسّطة فأكثر فقط: على الهاتف
             كان الاسم الطويل يلتهم السطر كلّه فيُخفي «الرئيسية» والقسم. --}}
        <x-storefront.icon name="chevron-left" class="hidden sm:block w-3 h-3 opacity-50 ltr:rotate-180" />
        <span class="hidden sm:block font-semibold text-[color:var(--sf-text)] truncate max-w-[24ch]">{{ $displayName }}</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-12">
        {{-- ══════════ المعرض ══════════ --}}
        <x-storefront.gallery :images="$images" :alt="$displayName" :discount="$discount" />

        {{-- ══════════ منطقة الشراء ══════════ --}}
        <div class="min-w-0">
            @if ($product->brand)
                <a href="{{ route('storefront.brand', $product->brand->slug) }}"
                   class="text-sm font-semibold text-brand-600 hover:text-brand-700 transition-colors">{{ $product->brand->name }}</a>
            @endif

            <h1 class="text-xl sm:text-2xl lg:text-3xl font-extrabold leading-snug mt-1 text-[color:var(--sf-text)]">
                {{ $displayName }}
            </h1>

            <p class="mt-1.5 text-xs text-[color:var(--sf-text-soft)] font-mono">{{ __('storefront.sku') }}: {{ $product->sku }}</p>

            @if ($hasOptions)
                {{-- منتج بخيارات: السعر والتوفّر والإضافة تتبع المتغيّر المختار --}}
                <div x-data="variantPicker(@js($optionGroups), @js($variantsJs), { price: {{ (float) $price }}, regular: {{ (float) $regular }} })"
                     class="mt-4">

                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="sf-price text-2xl sm:text-3xl"><span x-text="money(displayPrice)"></span> {{ __('storefront.currency') }}</span>
                        <template x-if="displayOnSale">
                            <span class="sf-price-old !text-base"><span x-text="money(displayRegular)"></span> {{ __('storefront.currency') }}</span>
                        </template>
                        <template x-if="displayOnSale">
                            <span class="sf-badge sf-badge-discount">{{ __('storefront.off') }} <span x-text="discountPct"></span>%</span>
                        </template>
                    </div>

                    @if ($shortDesc)
                        <p class="mt-4 text-sm leading-relaxed text-[color:var(--sf-text-soft)]">{{ $shortDesc }}</p>
                    @endif

                    {{-- مُنتقيات الخيارات --}}
                    <div class="mt-5 space-y-4">
                        <template x-for="group in groups" :key="group.id">
                            <div>
                                <div class="sf-label" x-text="group.name"></div>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="val in group.values" :key="val.id">
                                        <button type="button" @click="pick(group.id, val.id)"
                                                :aria-pressed="selected[group.id] === val.id"
                                                :class="selected[group.id] === val.id
                                                    ? 'border-brand-600 bg-brand-50 text-brand-700'
                                                    : 'border-[color:var(--sf-border)] text-[color:var(--sf-text)] hover:border-brand-300'"
                                                class="inline-flex items-center gap-1.5 rounded-[10px] border min-h-10 px-3.5 text-sm font-semibold transition-colors">
                                            <template x-if="val.color">
                                                <span class="inline-block w-4 h-4 rounded-full border border-[color:var(--sf-border)]"
                                                      :style="`background:${val.color}`"></span>
                                            </template>
                                            <span x-text="val.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- التوفّر --}}
                    <div class="mt-4 text-sm">
                        <template x-if="!isComplete">
                            <span class="sf-badge sf-badge-muted">{{ __('storefront.select_options') }}</span>
                        </template>
                        <template x-if="isComplete && matched && matched.available">
                            <span class="sf-badge sf-badge-success">
                                <x-storefront.icon name="check-circle" class="w-3.5 h-3.5" />{{ __('storefront.in_stock') }}
                            </span>
                        </template>
                        <template x-if="isComplete && (!matched || !matched.available)">
                            <span class="sf-badge sf-badge-muted">{{ __('storefront.combination_unavailable') }}</span>
                        </template>
                    </div>

                    {{-- الإضافة للسلة (نفس مكوّن البطاقة، والمتغيّر يتبع الاختيار) --}}
                    <div class="mt-6" id="sf-buy">
                        <x-storefront.add-to-cart size="lg"
                            variant-expr="matched?.uuid ?? null"
                            max-expr="matched?.max ?? 0"
                            enabled-expr="canAdd" />
                    </div>
                </div>
            @else
                <div class="mt-4">
                    <x-storefront.price :price="$price" :regular="$onSale ? $regular : null" size="lg" />
                    @if ($discount > 0)
                        <span class="sf-badge sf-badge-discount ms-2 align-middle">{{ __('storefront.off') }} {{ $discount }}%</span>
                    @endif
                </div>

                <div class="mt-3">
                    @if ($inStock)
                        <span class="sf-badge sf-badge-success">
                            <x-storefront.icon name="check-circle" class="w-3.5 h-3.5" />{{ __('storefront.in_stock') }}
                        </span>
                    @else
                        <span class="sf-badge sf-badge-muted">{{ __('storefront.out_of_stock') }}</span>
                    @endif
                </div>

                @if ($shortDesc)
                    <p class="mt-4 text-sm leading-relaxed text-[color:var(--sf-text-soft)]">{{ $shortDesc }}</p>
                @endif

                <div class="mt-6" id="sf-buy">
                    @if ($variant && $inStock)
                        <x-storefront.add-to-cart size="lg" :variant="$variant->uuid" :max="(int) floor($available)" />
                    @else
                        <button type="button" disabled class="sf-btn sf-btn-lg sf-btn-block bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)]">
                            {{ __('storefront.out_of_stock') }}
                        </button>
                    @endif
                </div>
            @endif

            {{-- المفضّلة والمشاركة --}}
            <div class="mt-4 flex items-center gap-2 flex-wrap">
                @if ($wishCustomer)
                    <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}">
                        @csrf
                        <button type="submit" class="sf-btn-outline min-h-10 {{ $inWishlist ? '!text-[color:var(--sf-danger)] !border-[color:var(--sf-danger)]' : '' }}">
                            <x-storefront.icon name="heart" class="w-4 h-4" :filled="$inWishlist" />
                            {{ $inWishlist ? __('account.in_wishlist') : __('account.add_to_wishlist') }}
                        </button>
                    </form>
                @elseif (! auth()->check())
                    <a href="{{ route('account.login') }}" class="sf-btn-outline min-h-10">
                        <x-storefront.icon name="heart" class="w-4 h-4" />
                        {{ __('account.add_to_wishlist') }}
                    </a>
                @endif

                <button type="button" x-data="{ shared: false }"
                        @click="navigator.share
                            ? navigator.share({ title: @js($displayName), url: window.location.href })
                            : (navigator.clipboard.writeText(window.location.href), shared = true, setTimeout(() => shared = false, 1500))"
                        class="sf-btn-outline min-h-10">
                    <x-storefront.icon name="share" class="w-4 h-4" />
                    <span x-show="!shared">{{ __('storefront.share') }}</span>
                    <span x-show="shared" x-cloak>{{ __('storefront.link_copied') }}</span>
                </button>
            </div>

            {{-- معلومات المنتج — الموجود فعلًا فقط --}}
            @if ($infoRows !== [])
                <dl class="mt-6 pt-5 border-t border-[color:var(--sf-border)] grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1 text-sm">
                    @foreach ($infoRows as $label => $value)
                        <div class="flex items-center justify-between gap-3 py-2 border-b border-[color:var(--sf-border)] last:border-0 sm:border-0">
                            <dt class="text-[color:var(--sf-text-soft)]">{{ $label }}</dt>
                            <dd class="font-semibold text-[color:var(--sf-text)] text-end">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            {{-- المواصفات (تُخفى لمنتجات الخيارات لأن السمات تظهر كمُنتقيات) --}}
            @if (! $hasOptions && $product->attributes->isNotEmpty())
                <div class="mt-5 pt-5 border-t border-[color:var(--sf-border)]">
                    <h2 class="font-bold mb-2 text-[color:var(--sf-text)]">{{ __('storefront.attributes') }}</h2>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 text-sm">
                        @foreach ($product->attributes as $attribute)
                            <div class="flex items-center justify-between gap-3 py-2 border-b border-[color:var(--sf-border)]">
                                <dt class="text-[color:var(--sf-text-soft)]">{{ $attribute->name }}</dt>
                                <dd class="font-semibold text-[color:var(--sf-text)] text-end">{{ $attribute->pivot->value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </div>

    {{-- ══════════ الوصف ══════════ --}}
    @if ($desc)
        <section class="mt-10">
            <h2 class="sf-section-title mb-3">{{ __('storefront.description') }}</h2>
            <div class="text-[15px] leading-8 text-[color:var(--sf-text-soft)] whitespace-pre-line max-w-3xl">{{ $desc }}</div>
        </section>
    @endif

    {{-- ══════════ أقسام التوصيات (مُتتبَّعة: ظهور/نقر) ══════════ --}}
    <x-storefront.section :title="__('storefront.frequently_bought')" :items="$frequentlyBoughtTogether" recoType="fbt" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.related')" :items="$related" recoType="related" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.cross_sell')" :items="$crossSell" recoType="cross_sell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.upsell')" :items="$upsell" recoType="upsell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.bundles')" :items="$bundles" recoType="complementary" placement="product" :source="$product->id" />

    {{-- ══════════ شريط الشراء اللاصق (جوّال) ══════════
         يظهر فقط بعد أن يخرج زرّ الشراء الأصلي من الشاشة، فلا يتكرّر الزرّ مرّتين،
         ويجلس فوق شريط التنقّل السفلي لا فوقه. --}}
    @if ($inStock || $hasOptions)
        <div x-data="{ show: false }"
             x-init="new IntersectionObserver(([e]) => show = !e.isIntersecting, { rootMargin: '-80px 0px 0px 0px' })
                        .observe(document.getElementById('sf-buy'))"
             x-show="show" x-cloak x-transition.opacity
             class="lg:hidden fixed inset-x-0 z-30 bg-white border-t border-[color:var(--sf-border)] px-4 py-2.5
                    flex items-center gap-3 shadow-[0_-2px_12px_rgba(34,34,34,.06)]"
             style="bottom: calc(var(--sf-bottomnav) + env(safe-area-inset-bottom, 0px))">
            <div class="min-w-0 flex-1">
                <span class="block text-[11px] text-[color:var(--sf-text-soft)] line-clamp-1">{{ $displayName }}</span>
                <x-storefront.price :price="$price" :regular="$onSale ? $regular : null" size="sm" />
            </div>
            <a href="#sf-buy" class="sf-btn-primary shrink-0">
                <x-storefront.icon name="cart" class="w-4 h-4" />
                {{ __('storefront.add_to_cart') }}
            </a>
        </div>
    @endif

    {{-- مُنتقي المتغيّرات (مقاسات/ألوان): يطابق الاختيار بمتغيّر ويحدّث السعر/التوفّر --}}
    @if ($hasOptions)
        <script>
            function variantPicker(groups, variants, base) {
                return {
                    groups: groups,
                    variants: variants,
                    base: base,
                    selected: groups.reduce((acc, g) => { acc[g.id] = null; return acc; }, {}),
                    pick(attrId, valueId) {
                        this.selected[attrId] = this.selected[attrId] === valueId ? null : valueId;
                    },
                    get isComplete() {
                        return this.groups.every(g => this.selected[g.id]);
                    },
                    get matched() {
                        if (!this.isComplete) return null;
                        const sel = this.groups.map(g => Number(this.selected[g.id])).sort((a, b) => a - b).join('-');
                        return this.variants.find(v => v.values.map(Number).sort((a, b) => a - b).join('-') === sel) || null;
                    },
                    get canAdd() {
                        return !!(this.matched && this.matched.available);
                    },
                    get displayPrice() {
                        return this.matched ? this.matched.price : this.base.price;
                    },
                    get displayRegular() {
                        return this.matched ? this.matched.regular : this.base.regular;
                    },
                    get displayOnSale() {
                        return this.matched ? this.matched.onSale : (this.base.price < this.base.regular - 1e-9);
                    },
                    get discountPct() {
                        const r = this.displayRegular, p = this.displayPrice;
                        return r > 0 ? Math.round((1 - p / r) * 100) : 0;
                    },
                    money(v) {
                        return Number(v).toFixed(2);
                    },
                };
            }
            document.addEventListener('alpine:init', () => window.Alpine.data('variantPicker', variantPicker));
        </script>
    @endif

    {{-- بيانات مهيكلة: منتج --}}
    @push('structured-data')
        <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $displayName,
            'sku' => $product->sku,
            'description' => (string) $metaDesc,
            'image' => $primary ? [$primary->url()] : [],
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => \App\Modules\Foundation\Services\Settings::get('store.currency', 'ILS'),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => route('storefront.product', $product->slug),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush
</x-storefront.layout>
