@props(['images', 'alt'])

{{--
    معرض صور المنتج — بلا أي مكتبة سلايدر.

    الحواسيب: صورة كبيرة + مصغّرات تحتها.
    الجوّال: شريط تمرير أفقي بالإصبع (scroll-snap) مع نقاط تُظهر الموضع — سلوك
    التمرير الأصلي للمتصفّح، فلا JavaScript للانزلاق ولا تكلفة أداء.

    صورة واحدة ⇒ لا مصغّرات ولا نقاط. بلا صور ⇒ نفس البديل المعتمد في المتجر.
    بلا شارة خصم فوق الصورة: مكانها الطبيعي بجانب السعر حيث يُتّخذ قرار الشراء،
    وتبقى الصورة نظيفة. (بطاقة القوائم تحتفظ بشارتها — سياق مختلف.)
--}}
@php
    $images = collect($images)->values();
    $count = $images->count();
@endphp

<div x-data="{ i: 0 }" class="lg:sticky lg:top-[7.5rem]">
    @if ($count === 0)
        <div class="sf-card aspect-square grid place-items-center bg-[color:var(--sf-bg)] text-gray-300">
            <x-storefront.icon name="image" class="w-20 h-20" />
        </div>
    @else
        {{-- ══ الجوّال: تمرير أفقي ══ --}}
        <div class="lg:hidden relative">
            <div class="flex overflow-x-auto snap-x snap-mandatory rounded-2xl bg-[color:var(--sf-bg)]"
                 style="scrollbar-width:none"
                 @scroll.debounce.100ms="i = Math.round($el.scrollLeft / $el.clientWidth) * ({{ $count > 1 ? 1 : 0 }})">
                @foreach ($images as $image)
                    <img src="{{ $image->url() }}" alt="{{ $image->alt ?: $alt }}"
                         width="800" height="800"
                         loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async"
                         @if ($loop->first) fetchpriority="high" @endif
                         class="w-full aspect-square object-cover shrink-0 snap-center">
                @endforeach
            </div>

            @if ($count > 1)
                <div class="flex items-center justify-center gap-1.5 mt-3" aria-hidden="true">
                    @foreach ($images as $idx => $image)
                        <span class="h-1.5 rounded-full transition-all"
                              :class="Math.abs(i) === {{ $idx }} ? 'w-5 bg-brand-600' : 'w-1.5 bg-[color:var(--sf-border)]'"></span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ══ الحواسيب: صورة كبيرة + مصغّرات ══ --}}
        <div class="hidden lg:block">
            <div class="relative sf-card overflow-hidden aspect-square bg-[color:var(--sf-bg)]">
                @foreach ($images as $idx => $image)
                    <img src="{{ $image->url() }}" alt="{{ $image->alt ?: $alt }}"
                         width="800" height="800"
                         loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async"
                         @if ($loop->first) fetchpriority="high" @endif
                         x-show="i === {{ $idx }}" @if (! $loop->first) x-cloak @endif
                         class="absolute inset-0 w-full h-full object-cover">
                @endforeach
            </div>

            @if ($count > 1)
                <div class="mt-3 grid grid-cols-5 gap-2" role="tablist" aria-label="{{ __('storefront.product_images') }}">
                    @foreach ($images as $idx => $image)
                        <button type="button" @click="i = {{ $idx }}" role="tab"
                                :aria-selected="i === {{ $idx }}"
                                class="aspect-square rounded-lg overflow-hidden border-2 transition-colors"
                                :class="i === {{ $idx }} ? 'border-brand-600' : 'border-[color:var(--sf-border)] hover:border-brand-300'"
                                aria-label="{{ __('storefront.image_number', ['n' => $idx + 1]) }}">
                            <img src="{{ $image->url() }}" alt="" width="160" height="160"
                                 loading="lazy" decoding="async" class="w-full h-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
