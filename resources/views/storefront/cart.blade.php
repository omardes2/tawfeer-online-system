<x-storefront.layout :title="__('storefront.cart')">

@php
    // بوّابة الطلب أونلاين: عند الإطفاء يُستبدَل زرّ «المتابعة للدفع» بتنبيه،
    // فلا يُرسَل الزبون إلى صفحة مغلقة بعد أن ملأ سلّته.
    $ordersEnabled = \App\Http\Middleware\EnsureOnlineOrdersEnabled::enabled();
@endphp

    {{--
        إعادة تصميم بصرية فقط. كل ارتباطات Alpine واستدعاءات مخزن السلة
        (`setQty` / `remove` / `refresh`) ومفاتيح البيانات (`variant_id`, `sku`,
        `qty`, `unit_price`, `line_total`, `subtotal`, `count`) كما هي حرفيًا.
    --}}

    <x-storefront.page-header :title="__('storefront.cart')"
        :breadcrumbs="[__('storefront.home') => route('storefront.home'), __('storefront.cart') => null]" />

    <div x-data>
        {{-- تحميل: هيكل بأبعاد المحتوى الحقيقي فلا تقفز الصفحة --}}
        <template x-if="$store.cart.loading">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-3">
                    <span class="sf-skeleton h-24 w-full"></span>
                    <span class="sf-skeleton h-24 w-full"></span>
                </div>
                <span class="sf-skeleton h-48 w-full"></span>
            </div>
        </template>

        {{-- خطأ --}}
        <template x-if="$store.cart.error && !$store.cart.loading">
            <div class="sf-card px-6 py-10 text-center">
                <span class="mx-auto mb-4 grid place-items-center w-14 h-14 rounded-full"
                      style="background:#FBE9E7;color:var(--sf-danger)">
                    <x-storefront.icon name="close" class="w-7 h-7" />
                </span>
                <p class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.error') }}</p>
                <button type="button" @click="$store.cart.refresh()" class="sf-btn-primary mt-4">
                    {{ __('storefront.retry') }}
                </button>
            </div>
        </template>

        {{-- فارغة --}}
        <template x-if="!$store.cart.loading && !$store.cart.error && $store.cart.count === 0">
            <div>
                <x-storefront.empty-state icon="cart"
                    :title="__('storefront.empty_cart')"
                    :description="__('storefront.empty_cart_hint')"
                    :action="route('storefront.shop')"
                    :action-label="__('storefront.continue_shopping')" />
            </div>
        </template>

        {{-- بنود السلة --}}
        <template x-if="!$store.cart.loading && !$store.cart.error && $store.cart.count > 0">
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-6">
                {{-- البنود --}}
                <div class="space-y-3 min-w-0">
                    <template x-for="item in $store.cart.items" :key="item.variant_id">
                        <div class="sf-card p-3 sm:p-4 flex items-start sm:items-center gap-3 sm:gap-4">
                            <span class="grid place-items-center h-16 w-16 shrink-0 rounded-xl
                                         bg-[color:var(--sf-bg)] text-gray-300">
                                <x-storefront.icon name="image" class="w-8 h-8" />
                            </span>

                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm text-[color:var(--sf-text)] truncate" x-text="item.sku"></p>
                                <p class="sf-price text-sm mt-0.5"
                                   x-text="`${Number(item.unit_price).toFixed(2)} {{ __('storefront.currency') }}`"></p>

                                {{-- الجوّال: الكمية والإجمالي تحت الاسم --}}
                                {{-- flex-wrap: عند ‎320px‎ لا يتّسع الصفّ للكمية والإجمالي معًا فينزل الإجمالي سطرًا --}}
                                <div class="flex sm:hidden flex-wrap items-center justify-between gap-x-3 gap-y-2 mt-3">
                                    {{-- shrink-0: بدونه تنضغط أزرار الكمية إلى ‎18px‎ على شاشة ‎320px‎ --}}
                                    <div class="sf-qty shrink-0">
                                        <button type="button"
                                                @click="$store.cart.setQty(item.variant_id, Math.max(0, Number(item.qty) - 1))"
                                                aria-label="{{ __('storefront.decrease') }}">−</button>
                                        <output x-text="Number(item.qty)"></output>
                                        <button type="button"
                                                @click="$store.cart.setQty(item.variant_id, Number(item.qty) + 1)"
                                                aria-label="{{ __('storefront.increase') }}">+</button>
                                    </div>
                                    <span class="font-bold tabular-nums whitespace-nowrap text-[color:var(--sf-text)]"
                                          x-text="`${Number(item.line_total).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                                </div>
                            </div>

                            {{-- الحواسيب: الكمية والإجمالي في صفّ واحد --}}
                            <div class="hidden sm:block">
                                <div class="sf-qty">
                                    <button type="button"
                                            @click="$store.cart.setQty(item.variant_id, Math.max(0, Number(item.qty) - 1))"
                                            aria-label="{{ __('storefront.decrease') }}">−</button>
                                    <output x-text="Number(item.qty)"></output>
                                    <button type="button"
                                            @click="$store.cart.setQty(item.variant_id, Number(item.qty) + 1)"
                                            aria-label="{{ __('storefront.increase') }}">+</button>
                                </div>
                            </div>
                            <span class="hidden sm:block w-24 text-end font-bold tabular-nums whitespace-nowrap text-[color:var(--sf-text)]"
                                  x-text="`${Number(item.line_total).toFixed(2)} {{ __('storefront.currency') }}`"></span>

                            <button type="button" @click="$store.cart.remove(item.variant_id)"
                                    class="shrink-0 grid place-items-center w-10 h-10 rounded-xl text-[color:var(--sf-text-soft)]
                                           hover:text-[color:var(--sf-danger)] transition-colors"
                                    :aria-label="'{{ __('storefront.remove') }}'">
                                <x-storefront.icon name="trash" class="w-5 h-5" />
                            </button>
                        </div>
                    </template>

                    <a href="{{ route('storefront.shop') }}"
                       class="sf-section-link inline-flex items-center gap-1 min-h-10 py-2">
                        <x-storefront.icon name="chevron-right" class="w-4 h-4 ltr:rotate-180" />
                        {{ __('storefront.continue_shopping') }}
                    </a>
                </div>

                {{-- الملخّص --}}
                <div>
                    <div class="sf-card sf-card-pad lg:sticky lg:top-[7.5rem]">
                        <h2 class="font-bold mb-4 text-[color:var(--sf-text)]">{{ __('storefront.order_summary') }}</h2>

                        <div class="flex items-center justify-between text-sm text-[color:var(--sf-text-soft)] py-2">
                            <span>{{ __('storefront.subtotal') }}</span>
                            <span class="font-bold tabular-nums text-[color:var(--sf-text)]"
                                  x-text="`${Number($store.cart.subtotal).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                        </div>

                        <div class="flex items-center justify-between pt-3 mt-2 border-t border-[color:var(--sf-border)]">
                            <span class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.total') }}</span>
                            <span class="sf-price text-xl"
                                  x-text="`${Number($store.cart.subtotal).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                        </div>

                        @if ($ordersEnabled)
                            <a href="{{ route('storefront.checkout') }}" class="sf-btn-primary sf-btn-block sf-btn-lg mt-5">
                                {{ __('storefront.proceed_to_checkout') }}
                                <x-storefront.icon name="chevron-left" class="w-5 h-5 ltr:rotate-180" />
                            </a>

                            <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-[color:var(--sf-text-soft)]">
                                <x-storefront.icon name="shield" class="w-4 h-4" />
                                {{ __('storefront.trust_cod') }}
                            </p>
                        @else
                            <div class="sf-alert sf-alert-error mt-5 mb-0">
                                <x-storefront.icon name="close" />
                                <span>{{ __('storefront.orders_disabled_title') }}</span>
                            </div>
                            <p class="mt-2 text-xs text-[color:var(--sf-text-soft)]">{{ __('storefront.orders_disabled_body') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </template>

        {{-- توصيات في السلة (Phase 6 / ADR-045) — مُتتبَّعة --}}
        <x-storefront.section :title="__('storefront.recommended_for_you')" :items="$recommendations" recoType="personalized" placement="cart" />
    </div>

    {{-- شريط الإتمام اللاصق (جوّال) — يجلس فوق شريط التنقّل لا فوقه --}}
    @if ($ordersEnabled)
    <div x-data x-show="$store.cart.count > 0" x-cloak
         class="lg:hidden fixed inset-x-0 z-30 bg-white border-t border-[color:var(--sf-border)] px-4 py-2.5
                flex items-center gap-3 shadow-[0_-2px_12px_rgba(34,34,34,.06)]"
         style="bottom: calc(var(--sf-bottomnav) + env(safe-area-inset-bottom, 0px))">
        <div class="min-w-0 flex-1">
            <span class="block text-[11px] text-[color:var(--sf-text-soft)]">{{ __('storefront.total') }}</span>
            <span class="sf-price text-base"
                  x-text="`${Number($store.cart.subtotal).toFixed(2)} {{ __('storefront.currency') }}`"></span>
        </div>
        <a href="{{ route('storefront.checkout') }}" class="sf-btn-primary shrink-0">
            {{ __('storefront.proceed_to_checkout') }}
        </a>
    </div>
    @endif
</x-storefront.layout>
