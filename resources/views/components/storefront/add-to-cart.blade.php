@props([
    // معرّف المتغيّر: نصّ ثابت للمنتج البسيط، أو تعبير JS للمنتج ذي الخيارات
    // (حيث يتغيّر المتغيّر مع اختيار المقاس/اللون).
    'variant' => null,
    'variantExpr' => null,
    'max' => 99,
    'maxExpr' => null,
    'size' => 'sm',
    'enabledExpr' => 'true',
    // صفحة المنتج على الجوّال: زرّ «أضف» يحمله الشريط اللاصق، فلا يُكرَّر هنا —
    // لكن محدّد الكمية يبقى ظاهرًا لأنه ليس تكرارًا بل تحكّم بما في السلة.
    'hideAddOnMobile' => false,
])

{{--
    زرّ الإضافة للسلة ومحدّد الكمية — **مصدر واحد للمنطق** يستخدمه كلٌّ من بطاقة
    المنتج وصفحة التفاصيل.

    الكمية تُقرأ من مخزن السلة الحقيقي لا من حالة محلّية: فتح صفحة على منتج موجود
    في السلة يعرض كميته، وأي تغيير من أي مكان ينعكس فورًا. لا إعادة تحميل، ولا
    تعديل على واجهة السلة.
--}}
@php
    $variantJs = $variantExpr ?: "'".$variant."'";
    $maxJs = $maxExpr ?: (int) $max;
    $big = $size === 'lg';
@endphp

<div x-data="{
        busy: false,
        get variant() { return {!! $variantJs !!}; },
        get max() { return {!! $maxJs !!}; },
        get enabled() { return !!({!! $enabledExpr !!}) && !!this.variant; },
        get qty() {
            // الواجهة تُعيد الكمية نصًّا عشريًا ('1.000')؛ بلا تحويل يصير الجمع دمجًا نصّيًا.
            const item = $store.cart.items.find(i => i.variant_id === this.variant);
            return item ? Math.round(Number(item.qty)) : 0;
        },
        async add() { this.busy = true; await $store.cart.add(this.variant, 1); this.busy = false; },
        async set(n) {
            this.busy = true;
            n <= 0 ? await $store.cart.remove(this.variant) : await $store.cart.setQty(this.variant, n);
            this.busy = false;
        },
     }"
     {{ $attributes->merge(['class' => 'w-full']) }}>

    {{-- بلا كمية: زرّ الإضافة --}}
    <button type="button" x-show="qty === 0" @click="add()" :disabled="busy || !enabled"
            @class([
                'sf-btn-primary sf-btn-block whitespace-nowrap',
                'min-h-10 !px-2 !py-2 text-[12px] sm:text-[13px] gap-1.5' => ! $big,
                'sf-btn-lg' => $big,
                'hidden lg:inline-flex' => $hideAddOnMobile,
            ])>
        <x-storefront.icon name="cart" :class="$big ? 'w-5 h-5' : 'w-4 h-4 hidden min-[360px]:block'" />
        {{ __('storefront.add_to_cart') }}
    </button>

    {{-- بعد الإضافة: محدّد الكمية --}}
    <div x-show="qty > 0" x-cloak @class(['sf-qty w-full justify-between', 'h-12' => $big])>
        <button type="button" @click="set(qty - 1)" :disabled="busy"
                :class="'{{ $big ? 'w-12 h-12' : '' }}'"
                :aria-label="qty === 1 ? '{{ __('storefront.remove') }}' : '{{ __('storefront.decrease') }}'">
            <span x-show="qty > 1" aria-hidden="true">−</span>
            <span x-show="qty === 1" x-cloak aria-hidden="true">
                <x-storefront.icon name="trash" class="w-4 h-4" />
            </span>
        </button>
        <output x-text="qty" aria-live="polite" @class(['font-bold', 'text-lg' => $big])></output>
        <button type="button" @click="set(qty + 1)" :disabled="busy || qty >= max"
                :class="'{{ $big ? 'w-12 h-12' : '' }}'"
                aria-label="{{ __('storefront.increase') }}">+</button>
    </div>
</div>
