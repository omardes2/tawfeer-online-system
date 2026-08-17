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

    // بحذف زرّ المفضّلة سقط استعلاما العميل والمفضّلة اللذان كانا يُنفَّذان
    // في كل عرض لصفحة منتج لمستخدم مسجَّل.

    // صفوف المعلومات — ما هو موجود فعلًا فقط، بلا صفوف فارغة.
    //
    // رمز المنتج والفئة والوحدة حُذفت: بيانات داخلية لا تُعين الزبون على قرار
    // الشراء، وكانت تحتلّ أبرز مساحة تحت السعر. مكانها الآن ضمانات الشراء.
    // العلامة التجارية باقية — تُغيّر القرار فعلًا.
    $infoRows = array_filter([
        __('storefront.brand') => $product->brand?->name,
    ]);

    // ضمانات الشراء: ثلاث بطاقات ثابتة تُطمئن قبل الإضافة إلى السلة.
    $assurances = [
        ['icon' => 'truck', 'title' => __('storefront.assure_delivery_title'), 'note' => __('storefront.assure_delivery_note')],
        ['icon' => 'shield', 'title' => __('storefront.assure_warranty_title'), 'note' => __('storefront.assure_warranty_note')],
        ['icon' => 'cash', 'title' => __('storefront.assure_cod_title'), 'note' => __('storefront.assure_cod_note')],
    ];
@endphp

<x-storefront.layout
    :title="$product->meta_title ?: $displayName"
    :description="$metaDesc"
    :canonical="route('storefront.product', $product->slug)"
    :image="$primary?->url()"
    :page-event="['name' => 'ProductViewed', 'payload' => ['product' => $product->uuid, 'sku' => $product->sku]]">

    {{-- مسار التنقّل — مضغوط على الجوّال --}}
    {{-- روابط المسار كانت 16px فقط؛ الحشو يرفع مساحة اللمس إلى 40px والهامش
         السالب على الشريط يُبقي ارتفاعه البصري كما هو. --}}
    <nav class="flex items-center gap-1.5 flex-wrap text-xs text-[color:var(--sf-text-soft)] mb-4 -my-1.5"
         aria-label="{{ __('storefront.breadcrumb') }}">
        <a href="{{ route('storefront.home') }}"
           class="inline-flex items-center min-h-10 -mx-1.5 px-1.5 hover:text-brand-600 transition-colors">{{ __('storefront.home') }}</a>
        @if ($product->category)
            <x-storefront.icon name="chevron-left" class="w-3 h-3 opacity-50 ltr:rotate-180" />
            <a href="{{ route('storefront.category', $product->category->slug) }}"
               class="inline-flex items-center min-h-10 -mx-1.5 px-1.5 hover:text-brand-600 transition-colors">{{ $product->category->name }}</a>
        @endif
        {{-- اسم المنتج يظهر في المسار على الشاشات المتوسّطة فأكثر فقط: على الهاتف
             كان الاسم الطويل يلتهم السطر كلّه فيُخفي «الرئيسية» والقسم. --}}
        <x-storefront.icon name="chevron-left" class="hidden sm:block w-3 h-3 opacity-50 ltr:rotate-180" />
        <span class="hidden sm:block font-semibold text-[color:var(--sf-text)] truncate max-w-[24ch]">{{ $displayName }}</span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-12">
        {{-- ══════════ المعرض ══════════ --}}
        <x-storefront.gallery :images="$images" :alt="$displayName" />

        {{-- ══════════ منطقة الشراء ══════════ --}}
        <div class="min-w-0">
            @if ($product->brand)
                <a href="{{ route('storefront.brand', $product->brand->slug) }}"
                   class="inline-flex items-center min-h-10 -my-2 -mx-2 px-2 text-sm font-semibold
                          text-brand-600 hover:text-brand-700 transition-colors">{{ $product->brand->name }}</a>
            @endif

            <h1 class="text-xl sm:text-2xl lg:text-3xl font-extrabold leading-snug mt-1 text-[color:var(--sf-text)]">
                {{ $displayName }}
            </h1>

            {{--
                رمز المنتج محذوف من العرض. كان يظهر هنا وفي جدول المعلومات معًا،
                وهو مرجع داخلي لا يقرأه الزبون. ما زال في حمولة حدث الصفحة
                (`ProductViewed`) وفي بنود السلة والطلب — لم يُمسّ أي عقد برمجي.
            --}}

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
                    <div class="mt-6 scroll-mt-32" id="sf-buy">
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

                {{-- زرّ «أضف» وحده يُخفى على الجوّال (الشريط اللاصق يحمله)، بينما محدّد
                     الكمية يبقى ظاهرًا تحت المنتج لأنه تحكّم بما في السلة لا تكرار. --}}
                <div class="mt-6 scroll-mt-32" id="sf-buy">
                    @if ($variant && $inStock)
                        <x-storefront.add-to-cart size="lg" :variant="$variant->uuid"
                            :max="(int) floor($available)" :hide-add-on-mobile="true" />
                    @else
                        <button type="button" disabled class="sf-btn sf-btn-lg sf-btn-block bg-[color:var(--sf-bg)] text-[color:var(--sf-text-soft)]">
                            {{ __('storefront.out_of_stock') }}
                        </button>
                    @endif
                </div>
            @endif

            {{--
                ضمانات الشراء مكان «أضف للمفضّلة/مشاركة».

                المفضّلة ما زالت متاحة من بطاقة المنتج في القوائم ومن صفحة
                «المفضّلة» في الحساب — الزرّ هنا وحده هو ما حُذف.
            --}}
            <ul class="mt-5 pt-5 border-t border-[color:var(--sf-border)] grid grid-cols-3 gap-2 sm:gap-3">
                @foreach ($assurances as $item)
                    <li class="flex flex-col items-center text-center gap-2">
                        <span class="grid place-items-center w-12 h-12 rounded-full bg-brand-50 text-brand-600 shrink-0">
                            <x-storefront.icon :name="$item['icon']" class="w-6 h-6" />
                        </span>
                        {{-- ‎11px‎ على الجوّال: ثلاثة أعمدة عند ‎320px‎ تترك ~‎96px‎ للعمود --}}
                        <span class="text-[11px] sm:text-xs font-bold leading-snug text-[color:var(--sf-text)]">
                            {{ $item['title'] }}
                        </span>
                        <span class="text-[10px] sm:text-[11px] leading-snug text-[color:var(--sf-text-soft)]">
                            {{ $item['note'] }}
                        </span>
                    </li>
                @endforeach
            </ul>

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

    {{-- ══════════ أقسام التوصيات (مُتتبَّعة: ظهور/نقر) ══════════
         قسمان كحدّ أقصى بدل خمسة: خمسة أقسام متتالية تُطيل الصفحة كثيرًا على
         الهاتف وتُكرّر المنتجات نفسها. الاختيار بأولوية ثابتة مع تخطّي الفارغ،
         وإزالة ما عُرض في القسم الأول من الثاني.
         **بلا أي مسّ بمحرّك التوصيات**: نفس المجموعات القادمة من المتحكّم،
         ونفس سمات التتبّع لكل قسم. --}}
    @php
        $recoCandidates = [
            ['items' => $frequentlyBoughtTogether, 'title' => __('storefront.frequently_bought'), 'type' => 'fbt'],
            ['items' => $related, 'title' => __('storefront.related'), 'type' => 'related'],
            ['items' => $crossSell, 'title' => __('storefront.cross_sell'), 'type' => 'cross_sell'],
            ['items' => $upsell, 'title' => __('storefront.upsell'), 'type' => 'upsell'],
            ['items' => $bundles, 'title' => __('storefront.bundles'), 'type' => 'complementary'],
        ];

        $recoSections = [];
        $shownIds = [];
        foreach ($recoCandidates as $candidate) {
            if (count($recoSections) === 2) {
                break;
            }
            $items = $candidate['items']->reject(fn ($p) => in_array($p->id, $shownIds, true))->values();
            if ($items->isEmpty()) {
                continue;
            }
            $shownIds = array_merge($shownIds, $items->pluck('id')->all());
            $recoSections[] = ['items' => $items, 'title' => $candidate['title'], 'type' => $candidate['type']];
        }
    @endphp

    {{-- التقييم وآراء الزبائن — قبل التوصيات: رأيٌ في هذا المنتج أولى من منتج آخر.
         يُخفى القسم كلّه بإعدادٍ واحد؛ قسمٌ فارغ يقول «لا توجد تقييمات» أسوأ من غيابه. --}}
    @if (\App\Modules\Foundation\Services\Settings::get('storefront.reviews_enabled', true))
        <x-storefront.reviews :product="$product" :summary="$reviewSummary" :reviews="$reviews"
            :can-review="$canReview" :existing="$existingReview" />
    @endif

    @foreach ($recoSections as $section)
        <x-storefront.section :title="$section['title']" :items="$section['items']"
            :reco-type="$section['type']" placement="product" :source="$product->id" />
    @endforeach

    {{-- الشريط اللاصق حاضر دائمًا على الجوّال، فيُحجز ارتفاعه هنا كي لا يغطّي
         آخر المحتوى (التوصيات) — بالإضافة إلى ما يحجزه شريط التنقّل السفلي. --}}
    @if ($inStock || $hasOptions)
        <div class="lg:hidden h-20" aria-hidden="true"></div>
    @endif

    {{-- ══════════ شريط الشراء اللاصق (جوّال) ══════════
         ظاهر طوال تصفّح صفحة المنتج على الجوّال — لا يظهر عند التمرير فقط. ولأنه
         حاضر دائمًا، أُزيل زرّ الشراء المكرّر من أعلى الصفحة على الجوّال (يبقى على
         الحواسيب حيث لا شريط لاصق). يجلس فوق شريط التنقّل السفلي لا فوقه.

         المنتج البسيط: يضيف للسلة بنفس مكوّن الزرّ الأصلي (مصدر منطق واحد)،
         ويتحوّل إلى محدّد كمية بعد الإضافة.

         المنتج ذو الخيارات: لا يمكن الإضافة قبل اختيار المقاس/اللون، فيبقى تمريرًا
         إلى المُنتقي — بنصّ يقول ذلك صراحةً بدل وعدٍ لا يفي به. --}}
    @if ($inStock || $hasOptions)
        <div data-buybar class="lg:hidden fixed inset-x-0 z-30 bg-white border-t border-[color:var(--sf-border)] px-4 py-2.5
                    flex items-center gap-3 shadow-[0_-2px_12px_rgba(34,34,34,.06)]"
             style="bottom: calc(var(--sf-bottomnav) + env(safe-area-inset-bottom, 0px))">
            <div class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-[color:var(--sf-text)] line-clamp-1">{{ $displayName }}</span>
                {{-- السعر يُعرَض للمنتج البسيط فقط: سعر المنتج ذي الخيارات يتبع المقاس/اللون
                     المختار، فعرض سعر أساسي هنا يناقض ما تراه في أعلى الصفحة. --}}
                @unless ($hasOptions)
                    <x-storefront.price :price="$price" :regular="$onSale ? $regular : null" size="sm" />
                @endunless
            </div>

            @if (! $hasOptions && $variant && $inStock)
                <div class="shrink-0 w-40">
                    <x-storefront.add-to-cart :variant="$variant->uuid" :max="(int) floor($available)" />
                </div>
            @else
                <a href="#sf-buy" class="sf-btn-primary shrink-0">
                    <x-storefront.icon name="chevron-down" class="w-4 h-4 rotate-180" />
                    {{ __('storefront.choose_options') }}
                </a>
            @endif
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
    {{-- تُبنى هنا لا داخل كتلة الدفع (لا يراها المكوّن هناك)، وداخل كتلة php
         لأن مفتاح السياق يُفسَّر كموجّه Blade خارجها فيخرج JSON-LD تالفًا. --}}
    @php
            $productLd = [
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
            ];
    @endphp

    @push('structured-data')
        <x-storefront.json-ld :data="$productLd" />
    @endpush
</x-storefront.layout>
