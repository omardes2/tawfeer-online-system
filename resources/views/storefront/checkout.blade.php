<x-storefront.layout :title="__('storefront.checkout')">
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('storefront.checkout') }}</h1>

    <div x-data="storefrontCheckout()" x-init="init()">
        {{-- سلة فارغة --}}
        <template x-if="empty">
            <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <p class="text-gray-700 font-medium">{{ __('storefront.empty_cart') }}</p>
                <a href="{{ route('storefront.shop') }}" class="inline-block mt-4 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5">{{ __('storefront.continue_shopping') }}</a>
            </div>
        </template>

        {{-- تأكيد الطلب --}}
        <template x-if="order">
            <div class="bg-white rounded-xl border border-emerald-200 p-10 text-center">
                <div class="mx-auto h-14 w-14 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-3">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                </div>
                <p class="text-lg font-bold text-gray-900">{{ __('storefront.order_placed') }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ __('storefront.order_number') }}: <span class="font-mono font-medium" x-text="order.order_number"></span></p>
                <p class="text-emerald-700 font-bold mt-2" x-text="`${Number(order.total).toFixed(2)} {{ __('storefront.currency') }}`"></p>
                <a href="{{ route('storefront.shop') }}" class="inline-block mt-5 text-sm text-emerald-600 hover:underline">{{ __('storefront.continue_shopping') }}</a>
            </div>
        </template>

        {{-- نموذج الإتمام --}}
        <template x-if="!empty && !order">
            <form @submit.prevent="place()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
                        <h2 class="font-bold text-gray-900">{{ __('storefront.contact_details') }}</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="c-name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.customer_name') }}</label>
                                <input id="c-name" x-model="form.customer_name" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label for="c-phone" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.customer_phone') }}</label>
                                <input id="c-phone" x-model="form.customer_phone" required inputmode="tel" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            </div>
                        </div>
                        <div>
                            <label for="c-email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('storefront.customer_email') }}</label>
                            <input id="c-email" type="email" x-model="form.customer_email" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
                        <h2 class="font-bold text-gray-900">{{ __('storefront.shipping_address') }}</h2>
                        <textarea id="c-address" x-model="form.shipping_address" required rows="3" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
                        <h2 class="font-bold text-gray-900">{{ __('storefront.payment_method') }}</h2>
                        <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer">
                            <input type="radio" name="pm" value="cod" x-model="form.payment_method_code" class="text-emerald-600 focus:ring-emerald-500">
                            <span class="text-gray-800">{{ __('storefront.cod') }}</span>
                        </label>
                    </div>
                </div>

                {{-- الملخّص --}}
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl border border-gray-200 p-5 sticky top-24">
                        <div class="flex items-center justify-between text-gray-700 mb-4">
                            <span>{{ __('storefront.subtotal') }}</span>
                            <span class="font-bold" x-text="`${Number($store.cart.subtotal).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                        </div>
                        <template x-if="error">
                            <p class="text-sm text-rose-600 mb-3" x-text="error"></p>
                        </template>
                        <button type="submit" :disabled="placing"
                                class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold py-3">
                            <span x-show="!placing">{{ __('storefront.place_order') }}</span>
                            <span x-show="placing" x-cloak>{{ __('storefront.loading') }}</span>
                        </button>
                        <a href="{{ route('storefront.cart') }}" class="block text-center mt-2 text-sm text-gray-500 hover:text-emerald-600">{{ __('storefront.cart') }}</a>
                    </div>
                </div>
            </form>
        </template>
    </div>

    <script>
        function storefrontCheckout() {
            const API = '/api/v1/store';
            return {
                sessionId: null,
                empty: false,
                placing: false,
                error: null,
                order: null,
                form: { customer_name: '', customer_phone: '', customer_email: '', shipping_address: '', payment_method_code: 'cod' },

                async init() {
                    try {
                        const res = await fetch(`${API}/checkout`, { method: 'POST', headers: window.StorefrontIdentity.headers() });
                        if (res.status === 422) { this.empty = true; return; }
                        if (!res.ok) throw new Error('start');
                        this.sessionId = (await res.json()).data.id;
                        window.StorefrontAnalytics.track('CheckoutStarted', { session: this.sessionId });
                    } catch (e) { this.error = '{{ __('storefront.error') }}'; }
                },

                async place() {
                    if (!this.sessionId) return;
                    this.placing = true; this.error = null;
                    try {
                        const h = window.StorefrontIdentity.headers();
                        await fetch(`${API}/checkout/${this.sessionId}`, { method: 'PATCH', headers: h, body: JSON.stringify(this.form) });
                        const res = await fetch(`${API}/checkout/${this.sessionId}/place`, { method: 'POST', headers: h });
                        if (!res.ok) throw new Error('place');
                        this.order = (await res.json()).data;
                        await Alpine.store('cart').refresh();
                    } catch (e) {
                        this.error = '{{ __('storefront.error') }}';
                    } finally {
                        this.placing = false;
                    }
                },
            };
        }
    </script>
</x-storefront.layout>
