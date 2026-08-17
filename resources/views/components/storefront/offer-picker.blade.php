@props([
    'product',
    'offers',
    'variants' => [],      // متغيّرات الخيارات لـJS
    'optionGroups' => [],
    'hasOptions' => false,
    'baseVariant' => null, // uuid المتغيّر الافتراضي للمنتج البسيط
    'basePrice' => 0,
    'regularPrice' => 0,
    'cities' => [],
    'areas' => [],
])

{{--
    ⚠️ Protected Delivery Integration — Do Not Modify.

    بطاقة عروض الكمّية. **شكلٌ حول المسار القائم لا مسار جديد**: الإضافة تمرّ
    على مخزن السلة نفسه، والإتمام يفتح لوح «شراء الآن» نفسه بتسلسله المعتاد.
    ولا حساب سعرٍ هنا — ما يُعرَض إعلانٌ، والسعر الفعليّ تحسبه الخلفية عند
    التسعير، ورسوم التوصيل تأتي من استجابة الـPATCH كما هي.

    والتصميم للجوّال أولًا: بطاقاتٌ عريضة تُلمَس بالإبهام، ومُنتقي مقاسٍ لكل
    قطعة داخل البطاقة المختارة وحدها — لا فوق بعضها.
--}}
@php
    $offersJs = $offers->map(fn ($o) => [
        'id' => (int) $o->id,
        'qty' => (int) $o->min_qty,
        'total' => round((float) $o->total_price, 2),
        'unit_price' => $o->unitPrice(),
        'title' => $o->title(),
        'label' => $o->label,
    ])->values();

    // العرض الأول في القائمة هو السعر الأصلي — قطعةٌ واحدة، ليقارن الزبون.
    $single = [
        'id' => 0,
        'qty' => 1,
        'total' => round((float) $basePrice, 2),
        'unit_price' => round((float) $basePrice, 2),
        'title' => __('storefront.offer_single', ['p' => number_format((float) $basePrice, 2)]),
        'label' => __('storefront.offer_original'),
    ];

    $allOffers = collect([$single])->merge($offersJs)->values();
@endphp

<div class="mt-6 rounded-2xl border border-[color:var(--sf-border)] bg-[color:var(--sf-surface,transparent)] p-3 sm:p-4"
     x-data="productOffers(@js($allOffers), @js($variants), {
         hasOptions: {{ $hasOptions ? 'true' : 'false' }},
         basePrice: {{ (float) $basePrice }},
         baseVariant: {{ $baseVariant ? "'".$baseVariant."'" : 'null' }},
     })">

    <div class="flex items-start gap-3 mb-3">
        <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand-50 text-brand-600 shrink-0">
            <x-storefront.icon name="tag" class="w-5 h-5" />
        </span>
        <div class="min-w-0">
            <p class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.offers_title') }}</p>
            <p class="text-xs text-[color:var(--sf-text-soft)]">{{ __('storefront.offers_hint') }}</p>
        </div>
    </div>

    <div class="space-y-2.5">
        <template x-for="o in offers" :key="o.id">
            <div class="relative rounded-xl border-2 transition-colors"
                 :class="selected === o.id ? 'border-brand-600' : 'border-[color:var(--sf-border)]'">

                {{-- الوسم فوق الحافّة كما في المرجع --}}
                <template x-if="o.label">
                    <span class="absolute -top-2.5 end-4 rounded-md bg-brand-600 px-2 py-0.5 text-[11px] font-bold text-white"
                          x-text="o.label"></span>
                </template>

                {{-- منطقة اللمس كلّها تختار: الإبهام لا يصيب زرًّا صغيرًا --}}
                <button type="button" @click="choose(o.id)"
                        class="w-full flex items-center gap-3 p-3.5 text-start">
                    <span class="grid place-items-center w-5 h-5 rounded-full border-2 shrink-0"
                          :class="selected === o.id ? 'border-brand-600' : 'border-[color:var(--sf-border)]'">
                        <span x-show="selected === o.id" class="w-2.5 h-2.5 rounded-full bg-brand-600"></span>
                    </span>

                    <span class="flex-1 min-w-0">
                        <span class="block font-bold text-sm text-[color:var(--sf-text)]" x-text="o.title"></span>
                    </span>

                    {{-- السعر الأصلي مشطوبًا بجانب سعر القطعة داخل العرض --}}
                    <span class="text-end shrink-0">
                        <span class="block sf-price text-lg" x-text="money(o.unit_price) + ' {{ __('storefront.currency') }}'"></span>
                        <template x-if="o.unit_price < {{ (float) $regularPrice }} - 0.001">
                            <span class="block sf-price-old !text-xs" x-text="money({{ (float) $regularPrice }}) + ' {{ __('storefront.currency') }}'"></span>
                        </template>
                    </span>
                </button>

                {{-- مُنتقي المقاس لكل قطعة — داخل البطاقة المختارة وحدها --}}
                <template x-if="selected === o.id && hasOptions">
                    <div class="px-3.5 pb-3.5 space-y-3 border-t border-[color:var(--sf-border)] pt-3">
                        <template x-for="u in units" :key="u">
                            <div>
                                <p class="text-xs font-semibold text-[color:var(--sf-text-soft)] mb-1.5"
                                   x-text="'{{ __('storefront.offer_pick_for_unit') }}'.replace(':n', u + 1)"></p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="v in available" :key="v.uuid">
                                        <button type="button" @click="pick(u, v.uuid)"
                                                :aria-pressed="picks[u] === v.uuid"
                                                :class="picks[u] === v.uuid
                                                    ? 'border-brand-600 bg-brand-50 text-brand-700'
                                                    : 'border-[color:var(--sf-border)] text-[color:var(--sf-text)]'"
                                                class="inline-flex items-center rounded-[10px] border min-h-10 px-3.5 text-sm font-semibold">
                                            <span x-text="v.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- الإجمالي — رقمٌ معروض لا محسوبٌ في الطلب: الخلفية تسعّر --}}
    <div class="mt-4 flex items-center justify-between text-sm">
        <span class="text-[color:var(--sf-text-soft)]">{{ __('storefront.offer_total') }}</span>
        <span class="sf-price text-xl" x-text="money(offer ? offer.total : basePrice) + ' {{ __('storefront.currency') }}'"></span>
    </div>

    <div class="mt-3 space-y-2">
        <button type="button" @click="add()" :disabled="!isComplete || busy"
                class="sf-btn-outline sf-btn-block sf-btn-lg">
            <span x-show="!busy">{{ __('storefront.add_to_cart') }}</span>
            <span x-show="busy" x-cloak>{{ __('storefront.loading') }}</span>
        </button>

        <button type="button" @click="buyNow()" :disabled="!isComplete || busy"
                class="sf-btn-primary sf-btn-block sf-btn-lg">
            <x-storefront.icon name="bolt" class="w-5 h-5" />
            {{ __('storefront.buy_now') }}
        </button>

        <p x-show="!isComplete" x-cloak class="text-xs text-center text-[color:var(--sf-text-soft)]">
            {{ __('storefront.offer_pick_all') }}
        </p>
    </div>

    {{-- لوحُ الإتمام الذي يفتحه زرّ الشراء أعلاه — بلا زرٍّ خاصّ به --}}
    <x-storefront.quick-buy :cities="$cities" :areas="$areas" listens triggerless variant="none" />
</div>
