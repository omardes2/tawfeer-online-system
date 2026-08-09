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
        ];
    })->values();
@endphp

<x-storefront.layout
    :title="$product->meta_title ?: $displayName"
    :description="$metaDesc"
    :canonical="route('storefront.product', $product->slug)"
    :image="$primary?->url()"
    :page-event="['name' => 'ProductViewed', 'payload' => ['product' => $product->uuid, 'sku' => $product->sku]]">

    {{-- مسار التنقّل --}}
    <nav class="text-xs text-gray-500 mb-4 flex items-center gap-1.5 flex-wrap" aria-label="breadcrumb">
        <a href="{{ route('storefront.home') }}" class="hover:text-emerald-600">{{ __('storefront.home') }}</a>
        <span>/</span>
        @if ($product->category)
            <a href="{{ route('storefront.category', $product->category->slug) }}" class="hover:text-emerald-600">{{ $product->category->name }}</a>
            <span>/</span>
        @endif
        <span class="text-gray-700">{{ $displayName }}</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- المعرض --}}
        <div x-data="{ main: '{{ $primary?->url() }}' }">
            <div class="aspect-square bg-gray-100 rounded-2xl overflow-hidden flex items-center justify-center">
                @if ($primary)
                    <img :src="main" alt="{{ $primary->alt ?: $displayName }}" class="w-full h-full object-cover">
                @else
                    <svg class="h-24 w-24 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 3.75h16.5v16.5H3.75V3.75Z"/></svg>
                @endif
            </div>
            @if ($images->count() > 1)
                <div class="mt-3 grid grid-cols-5 gap-2">
                    @foreach ($images as $image)
                        <button type="button" @click="main = '{{ $image->url() }}'"
                                class="aspect-square rounded-lg overflow-hidden border-2 border-transparent focus:border-emerald-500"
                                :class="main === '{{ $image->url() }}' ? 'border-emerald-500' : 'border-gray-200'">
                            <img src="{{ $image->url() }}" alt="{{ $image->alt ?: $displayName }}" loading="lazy" class="w-full h-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- التفاصيل --}}
        <div>
            @if ($product->brand)
                <a href="{{ route('storefront.brand', $product->brand->slug) }}" class="text-sm text-emerald-600 hover:underline">{{ $product->brand->name }}</a>
            @endif
            <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ $displayName }}</h1>

            <div class="mt-2 text-sm text-gray-500 flex items-center gap-3">
                <span>{{ __('storefront.sku') }}: {{ $product->sku }}</span>
            </div>

            @if ($hasOptions)
                {{-- منتج بخيارات (مقاسات/ألوان): السعر والتوفّر يتبعان المتغيّر المختار --}}
                <div x-data="variantPicker(@js($optionGroups), @js($variantsJs), { price: {{ (float) $price }}, regular: {{ (float) $regular }} })">
                    {{-- السعر --}}
                    <div class="mt-4 flex items-center gap-3">
                        <span class="text-3xl font-bold text-emerald-700"><span x-text="money(displayPrice)"></span> {{ __('storefront.currency') }}</span>
                        <template x-if="displayOnSale">
                            <span class="text-lg text-gray-400 line-through"><span x-text="money(displayRegular)"></span> {{ __('storefront.currency') }}</span>
                        </template>
                        <template x-if="displayOnSale">
                            <span class="bg-rose-100 text-rose-700 text-xs font-bold px-2 py-1 rounded"><span x-text="discountPct"></span>% {{ __('storefront.off') }}</span>
                        </template>
                    </div>

                    @if ($shortDesc)
                        <p class="mt-4 text-gray-600 leading-relaxed">{{ $shortDesc }}</p>
                    @endif

                    {{-- مُنتقيات الخيارات --}}
                    <div class="mt-5 space-y-4">
                        <template x-for="group in groups" :key="group.id">
                            <div>
                                <div class="text-sm font-medium text-gray-800 mb-1.5" x-text="group.name"></div>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="val in group.values" :key="val.id">
                                        <button type="button" @click="pick(group.id, val.id)"
                                                :class="selected[group.id] === val.id ? 'border-emerald-600 ring-1 ring-emerald-600 text-emerald-700 bg-emerald-50' : 'border-gray-300 text-gray-700'"
                                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm hover:border-emerald-400">
                                            <template x-if="val.color"><span class="inline-block w-3.5 h-3.5 rounded-full border border-gray-300" :style="`background:${val.color}`"></span></template>
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
                            <span class="text-gray-500">{{ __('storefront.select_options') }}</span>
                        </template>
                        <template x-if="isComplete && matched && matched.available">
                            <span class="inline-flex items-center gap-1.5 text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ __('storefront.in_stock') }}</span>
                        </template>
                        <template x-if="isComplete && (!matched || !matched.available)">
                            <span class="inline-flex items-center gap-1.5 text-gray-500"><span class="h-2 w-2 rounded-full bg-gray-400"></span>{{ __('storefront.combination_unavailable') }}</span>
                        </template>
                    </div>

                    {{-- الكمية + الإضافة للسلة --}}
                    <div class="mt-6 flex items-center gap-3" x-data="{ qty: 1, done: false }">
                        <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="-">−</button>
                            <input type="text" x-model="qty" readonly class="w-12 text-center border-0 focus:ring-0 p-0" aria-label="{{ __('storefront.qty') }}">
                            <button type="button" @click="qty++" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="+">+</button>
                        </div>
                        <button type="button" :disabled="!canAdd"
                                @click="if (canAdd) { await $store.cart.add(matched.uuid, qty); done = true; setTimeout(() => done = false, 1500) }"
                                :class="canAdd ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg font-semibold px-6 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1">
                            <span x-show="!done">{{ __('storefront.add_to_cart') }}</span>
                            <span x-show="done" x-cloak>✓ {{ __('storefront.added') }}</span>
                        </button>
                    </div>
                </div>
            @else
                {{-- السعر --}}
                <div class="mt-4 flex items-center gap-3">
                    <span class="text-3xl font-bold text-emerald-700">{{ number_format($price, 2) }} {{ __('storefront.currency') }}</span>
                    @if ($onSale)
                        <span class="text-lg text-gray-400 line-through">{{ number_format($regular, 2) }} {{ __('storefront.currency') }}</span>
                        <span class="bg-rose-100 text-rose-700 text-xs font-bold px-2 py-1 rounded">
                            {{ (int) round((1 - $price / max($regular, 0.01)) * 100) }}% {{ __('storefront.off') }}
                        </span>
                    @endif
                </div>

                {{-- التوفّر --}}
                <div class="mt-3">
                    @if ($inStock)
                        <span class="inline-flex items-center gap-1.5 text-sm text-emerald-700">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ __('storefront.in_stock') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-sm text-gray-500">
                            <span class="h-2 w-2 rounded-full bg-gray-400"></span>{{ __('storefront.out_of_stock') }}
                        </span>
                    @endif
                </div>

                @if ($shortDesc)
                    <p class="mt-4 text-gray-600 leading-relaxed">{{ $shortDesc }}</p>
                @endif

                {{-- إضافة للسلة --}}
                @if ($variant && $inStock)
                    <div class="mt-6 flex items-center gap-3" x-data="{ qty: 1, done: false }">
                        <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="-">−</button>
                            <input type="text" x-model="qty" readonly class="w-12 text-center border-0 focus:ring-0 p-0" aria-label="{{ __('storefront.qty') }}">
                            <button type="button" @click="qty++" class="px-3 py-2 text-gray-600 hover:bg-gray-50" aria-label="+">+</button>
                        </div>
                        <button type="button"
                                @click="await $store.cart.add('{{ $variant->uuid }}', qty); done = true; setTimeout(() => done = false, 1500)"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1">
                            <span x-show="!done">{{ __('storefront.add_to_cart') }}</span>
                            <span x-show="done" x-cloak>✓ {{ __('storefront.added') }}</span>
                        </button>
                    </div>
                @else
                    <div class="mt-6">
                        <button type="button" disabled class="w-full rounded-lg bg-gray-200 text-gray-400 font-semibold px-6 py-3 cursor-not-allowed">
                            {{ __('storefront.out_of_stock') }}
                        </button>
                    </div>
                @endif
            @endif

            {{-- المفضّلة --}}
            <div class="mt-4">
                @auth
                    @php
                        $wishCustomer = \App\Modules\Crm\Models\Customer::where('user_id', auth()->id())->first();
                        $inWishlist = $wishCustomer && app(\App\Modules\Store\Services\WishlistService::class)->has($wishCustomer, $product);
                    @endphp
                    @if ($wishCustomer)
                        <form method="POST" action="{{ route('account.wishlist.toggle', $product) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 text-sm {{ $inWishlist ? 'text-rose-600' : 'text-gray-500 hover:text-rose-600' }}">
                                <svg class="h-4 w-4" fill="{{ $inWishlist ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.6-10-9.2C.6 8.9 2 5.5 5.2 5.5c1.9 0 3.2 1.1 3.8 2.2.6-1.1 1.9-2.2 3.8-2.2 3.2 0 4.6 3.4 3.2 6.3C19.5 16.4 12 21 12 21Z"/></svg>
                                {{ $inWishlist ? __('account.in_wishlist') : __('account.add_to_wishlist') }}
                            </button>
                        </form>
                    @endif
                @else
                    <a href="{{ route('account.login') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-rose-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.6-10-9.2C.6 8.9 2 5.5 5.2 5.5c1.9 0 3.2 1.1 3.8 2.2.6-1.1 1.9-2.2 3.8-2.2 3.2 0 4.6 3.4 3.2 6.3C19.5 16.4 12 21 12 21Z"/></svg>
                        {{ __('account.add_to_wishlist') }}
                    </a>
                @endauth
            </div>

            {{-- المشاركة --}}
            <div class="mt-4" x-data="{ shared: false }">
                <button type="button"
                        @click="navigator.share ? navigator.share({ title: '{{ $displayName }}', url: window.location.href }) : (navigator.clipboard.writeText(window.location.href), shared = true, setTimeout(() => shared = false, 1500))"
                        class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-emerald-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7.2 10.5a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0Zm14.4-6a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0Zm0 12a2.4 2.4 0 1 1-4.8 0 2.4 2.4 0 0 1 4.8 0ZM7.2 10.5l9.6-4.5m-9.6 6 9.6 4.5"/></svg>
                    <span x-show="!shared">{{ __('storefront.share') }}</span>
                    <span x-show="shared" x-cloak>✓</span>
                </button>
            </div>

            {{-- المواصفات (تُخفى لمنتجات الخيارات لأن السمات تظهر كمُنتقيات) --}}
            @if (! $hasOptions && $product->attributes->isNotEmpty())
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h2 class="text-sm font-bold text-gray-900 mb-2">{{ __('storefront.attributes') }}</h2>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        @foreach ($product->attributes as $attribute)
                            <div class="flex justify-between gap-2 py-1 border-b border-gray-100">
                                <dt class="text-gray-500">{{ $attribute->name }}</dt>
                                <dd class="text-gray-800 font-medium">{{ $attribute->pivot->value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </div>

    {{-- الوصف الكامل --}}
    @if ($desc)
        <section class="mt-8 bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-3">{{ __('storefront.description') }}</h2>
            <div class="prose prose-sm max-w-none text-gray-600 leading-relaxed whitespace-pre-line">{{ $desc }}</div>
        </section>
    @endif

    {{-- أقسام التوصيات (Phase 6 / ADR-045) — مُتتبَّعة (ظهور/نقر) عبر JS المتجر --}}
    <x-storefront.section :title="__('storefront.frequently_bought')" :items="$frequentlyBoughtTogether" recoType="fbt" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.related')" :items="$related" recoType="related" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.cross_sell')" :items="$crossSell" recoType="cross_sell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.upsell')" :items="$upsell" recoType="upsell" placement="product" :source="$product->id" />
    <x-storefront.section :title="__('storefront.bundles')" :items="$bundles" recoType="complementary" placement="product" :source="$product->id" />

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
