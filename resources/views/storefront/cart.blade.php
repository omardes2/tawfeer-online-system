<x-storefront.layout :title="__('storefront.cart')">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('storefront.cart') }}</h1>

    <div x-data>
        {{-- تحميل --}}
        <template x-if="$store.cart.loading">
            <div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-400">{{ __('storefront.loading') }}</div>
        </template>

        {{-- خطأ --}}
        <template x-if="$store.cart.error && !$store.cart.loading">
            <div class="bg-white rounded-xl border border-rose-200 p-6 text-center">
                <p class="text-rose-600">{{ __('storefront.error') }}</p>
                <button type="button" @click="$store.cart.refresh()" class="mt-3 text-sm text-emerald-600 hover:underline">{{ __('storefront.retry') }}</button>
            </div>
        </template>

        {{-- فارغة --}}
        <template x-if="!$store.cart.loading && !$store.cart.error && $store.cart.count === 0">
            <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.3"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.4l2.1 12.3a1.5 1.5 0 0 0 1.48 1.2h9.4a1.5 1.5 0 0 0 1.47-1.17l1.6-7.03H6.1"/></svg>
                <p class="text-gray-700 font-medium">{{ __('storefront.empty_cart') }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ __('storefront.empty_cart_hint') }}</p>
                <a href="{{ route('storefront.shop') }}" class="inline-block mt-4 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('storefront.continue_shopping') }}</a>
            </div>
        </template>

        {{-- بنود السلة --}}
        <template x-if="!$store.cart.loading && !$store.cart.error && $store.cart.count > 0">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-3">
                    <template x-for="item in $store.cart.items" :key="item.variant_id">
                        <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
                            <div class="h-16 w-16 rounded-lg bg-gray-100 shrink-0 flex items-center justify-center text-gray-300">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75h16.5v16.5H3.75z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-800 truncate" x-text="item.sku"></p>
                                <p class="text-sm text-emerald-700 mt-0.5" x-text="`${Number(item.unit_price).toFixed(2)} {{ __('storefront.currency') }}`"></p>
                            </div>
                            <div class="inline-flex items-center rounded-lg border border-gray-300 overflow-hidden">
                                <button type="button" @click="$store.cart.setQty(item.variant_id, Math.max(0, Number(item.qty) - 1))" class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-50" aria-label="-">−</button>
                                <span class="w-10 text-center text-sm" x-text="Number(item.qty)"></span>
                                <button type="button" @click="$store.cart.setQty(item.variant_id, Number(item.qty) + 1)" class="px-2.5 py-1.5 text-gray-600 hover:bg-gray-50" aria-label="+">+</button>
                            </div>
                            <div class="w-24 text-end font-semibold text-gray-800" x-text="`${Number(item.line_total).toFixed(2)} {{ __('storefront.currency') }}`"></div>
                            <button type="button" @click="$store.cart.remove(item.variant_id)" class="text-gray-400 hover:text-rose-600 p-1" :aria-label="'{{ __('storefront.remove') }}'">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7.5h12M9.75 7.5V6a1.5 1.5 0 0 1 1.5-1.5h1.5A1.5 1.5 0 0 1 14.25 6v1.5m2.25 0-.6 11.1a1.5 1.5 0 0 1-1.5 1.4H9.6a1.5 1.5 0 0 1-1.5-1.4L7.5 7.5"/></svg>
                            </button>
                        </div>
                    </template>
                </div>

                {{-- الملخّص --}}
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl border border-gray-200 p-5 sticky top-24">
                        <div class="flex items-center justify-between text-gray-700 mb-4">
                            <span>{{ __('storefront.subtotal') }}</span>
                            <span class="font-bold" x-text="`${Number($store.cart.subtotal).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                        </div>
                        <a href="{{ route('storefront.checkout') }}" class="block text-center rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3">{{ __('storefront.proceed_to_checkout') }}</a>
                        <a href="{{ route('storefront.shop') }}" class="block text-center mt-2 text-sm text-gray-500 hover:text-emerald-600">{{ __('storefront.continue_shopping') }}</a>
                    </div>
                </div>
            </div>
        </template>

        {{-- توصيات في السلة (Phase 6 / ADR-045) — مُتتبَّعة --}}
        <x-storefront.section :title="__('storefront.recommended_for_you')" :items="$recommendations" recoType="personalized" placement="cart" />
    </div>
</x-storefront.layout>
