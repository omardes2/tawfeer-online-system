<x-storefront.layout :title="__('storefront.checkout')">

    {{--
        ⚠️ Protected Delivery Integration — Do Not Modify.

        محفوظ كما هو: `x-data="storefrontCheckout()"` و`x-init="init()"`، ومفاتيح
        النموذج (customer_name / customer_phone / shipping_address / city_id /
        area_id / payment_method_code)، ومعرّفات الحقول (c-name / c-phone /
        c-city / c-area / c-address)، وزرّ الدفع `name="pm" value="cod"`،
        و`@submit.prevent="place()"` وحالات empty / order / error / placing،
        ومسارات `/api/v1/store/checkout` وترويسات الهوية.

        البريد الإلكتروني حُذف من النموذج بطلب المالك — الحقل نفسه ما زال في
        الخلفية (`customer_email` اختياري) فتبقى طلبات لوحة الإدارة قادرة عليه.
    --}}

    <x-storefront.page-header :title="__('storefront.checkout')"
        :breadcrumbs="[
            __('storefront.home') => route('storefront.home'),
            __('storefront.cart') => route('storefront.cart'),
            __('storefront.checkout') => null,
        ]" />

    <div x-data="storefrontCheckout()" x-init="init()">
        {{-- سلة فارغة --}}
        <template x-if="empty">
            <div>
                <x-storefront.empty-state icon="cart"
                    :title="__('storefront.empty_cart')"
                    :description="__('storefront.empty_cart_hint')"
                    :action="route('storefront.shop')"
                    :action-label="__('storefront.continue_shopping')" />
            </div>
        </template>

        {{-- تأكيد الطلب --}}
        <template x-if="order">
            <div class="sf-card px-6 py-12 text-center max-w-lg mx-auto">
                <span class="mx-auto mb-5 grid place-items-center w-20 h-20 rounded-full"
                      style="background:#E7F6EE;color:var(--sf-success)">
                    <x-storefront.icon name="check-circle" class="w-11 h-11" />
                </span>

                <p class="text-xl font-extrabold text-[color:var(--sf-text)]">{{ __('storefront.order_placed') }}</p>

                <div class="mt-5 rounded-xl bg-[color:var(--sf-bg)] px-5 py-4 text-start space-y-2">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-[color:var(--sf-text-soft)]">{{ __('storefront.order_number') }}</span>
                        <span class="font-mono font-bold text-[color:var(--sf-text)]" x-text="order.order_number"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-[color:var(--sf-text-soft)]">{{ __('storefront.total') }}</span>
                        <span class="sf-price" x-text="`${Number(order.total).toFixed(2)} {{ __('storefront.currency') }}`"></span>
                    </div>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row gap-2 justify-center">
                    <a href="{{ auth()->check() ? route('account.orders') : route('storefront.shop') }}" class="sf-btn-primary">
                        {{ auth()->check() ? __('storefront.top_track') : __('storefront.continue_shopping') }}
                    </a>
                    <a href="{{ route('storefront.shop') }}" class="sf-btn-outline">{{ __('storefront.continue_shopping') }}</a>
                </div>
            </div>
        </template>

        {{-- نموذج الإتمام --}}
        <template x-if="!empty && !order">
            <form @submit.prevent="place()" class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-6">
                <div class="space-y-4 min-w-0">

                    {{-- ١) بيانات التواصل --}}
                    <section class="sf-card sf-card-pad">
                        <h2 class="flex items-center gap-2.5 font-bold mb-4 text-[color:var(--sf-text)]">
                            <span class="grid place-items-center w-7 h-7 rounded-full bg-brand-600 text-white text-xs font-bold shrink-0">1</span>
                            {{ __('storefront.contact_details') }}
                        </h2>

                        {{-- البريد الإلكتروني محذوف بطلب المالك: الطلب يُتابَع بالجوّال. --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="c-name" class="sf-label">{{ __('storefront.customer_name') }}</label>
                                <input id="c-name" x-model="form.customer_name" required class="sf-input">
                            </div>
                            <div>
                                <label for="c-phone" class="sf-label">{{ __('storefront.customer_phone') }}</label>
                                <input id="c-phone" x-model="form.customer_phone" required inputmode="tel" class="sf-input" dir="ltr">
                            </div>
                        </div>
                    </section>

                    {{-- ٢) عنوان الشحن --}}
                    <section class="sf-card sf-card-pad">
                        <h2 class="flex items-center gap-2.5 font-bold mb-4 text-[color:var(--sf-text)]">
                            <span class="grid place-items-center w-7 h-7 rounded-full bg-brand-600 text-white text-xs font-bold shrink-0">2</span>
                            {{ __('storefront.shipping_address') }}
                        </h2>
                        {{-- المدينة والمنطقة: من مدن شركة التوصيل المسعّرة في النظام.
                             اختيار المدينة يُرسَل للخلفية فورًا، والرسوم تعود منها. --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="c-city" class="sf-label">{{ __('storefront.city') }}</label>
                                <select id="c-city" x-model.number="form.city_id" @change="pickCity()" required class="sf-select">
                                    <option value="">{{ __('storefront.choose_city') }}</option>
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="c-area" class="sf-label">{{ __('storefront.area') }}</label>
                                <select id="c-area" x-model.number="form.area_id" @change="sync()"
                                        :disabled="!form.city_id" class="sf-select">
                                    <option value="">{{ __('storefront.choose_area') }}</option>
                                    <template x-for="a in areasOf(form.city_id)" :key="a.id">
                                        <option :value="a.id" x-text="a.name"></option>
                                    </template>
                                </select>
                                <p class="sf-hint" x-show="!form.city_id">{{ __('storefront.choose_city_first') }}</p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label for="c-address" class="sf-label">{{ __('storefront.address_details') }}</label>
                            <textarea id="c-address" x-model="form.shipping_address" required rows="3" class="sf-textarea"
                                      placeholder="{{ __('storefront.address_placeholder') }}"></textarea>
                            <p class="sf-hint">{{ __('storefront.address_hint') }}</p>
                        </div>
                    </section>

                    {{-- ٣) طريقة الدفع --}}
                    <section class="sf-card sf-card-pad">
                        <h2 class="flex items-center gap-2.5 font-bold mb-4 text-[color:var(--sf-text)]">
                            <span class="grid place-items-center w-7 h-7 rounded-full bg-brand-600 text-white text-xs font-bold shrink-0">3</span>
                            {{ __('storefront.payment_method') }}
                        </h2>
                        <label class="flex items-center gap-3 rounded-xl border-2 p-4 cursor-pointer transition-colors"
                               :class="form.payment_method_code === 'cod'
                                   ? 'border-brand-600 bg-brand-50'
                                   : 'border-[color:var(--sf-border)] hover:border-brand-300'">
                            <input type="radio" name="pm" value="cod" x-model="form.payment_method_code"
                                   class="text-brand-600 focus:ring-brand-500 w-5 h-5">
                            <span class="grid place-items-center w-10 h-10 rounded-xl bg-white text-brand-600 shrink-0">
                                <x-storefront.icon name="truck" class="w-5 h-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-[color:var(--sf-text)]">{{ __('storefront.cod') }}</span>
                                <span class="block text-xs text-[color:var(--sf-text-soft)]">{{ __('storefront.cod_hint') }}</span>
                            </span>
                        </label>
                    </section>
                </div>

                {{-- الملخّص --}}
                <div>
                    <div class="sf-card sf-card-pad lg:sticky lg:top-[7.5rem]">
                        <h2 class="font-bold mb-4 text-[color:var(--sf-text)]">{{ __('storefront.order_summary') }}</h2>

                        {{-- كل الأرقام من الخلفية (`totals`) — لا معادلة تسعير في الواجهة --}}
                        <div class="flex items-center justify-between text-sm text-[color:var(--sf-text-soft)] py-2">
                            <span>{{ __('storefront.subtotal') }}</span>
                            <span class="font-bold tabular-nums whitespace-nowrap text-[color:var(--sf-text)]"
                                  x-text="money(totals.subtotal)"></span>
                        </div>

                        <div class="flex items-center justify-between text-sm text-[color:var(--sf-text-soft)] py-2">
                            <span>{{ __('storefront.delivery_fee') }}</span>
                            <span x-show="!form.city_id" class="text-xs">{{ __('storefront.choose_city_for_fee') }}</span>
                            <span x-show="form.city_id" x-cloak
                                  class="font-bold tabular-nums whitespace-nowrap text-[color:var(--sf-text)]"
                                  x-text="totals.delivery_fee > 0 ? money(totals.delivery_fee) : '{{ __('storefront.free') }}'"></span>
                        </div>

                        <div class="flex items-center justify-between pt-3 mt-2 border-t border-[color:var(--sf-border)]">
                            <span class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.total') }}</span>
                            <span class="sf-price text-xl whitespace-nowrap" x-text="money(totals.total)"></span>
                        </div>

                        <template x-if="error">
                            <p class="mt-4 rounded-lg px-3 py-2 text-sm"
                               style="background:#FBE9E7;color:var(--sf-danger)" x-text="error"></p>
                        </template>

                        <button type="submit" :disabled="placing" class="sf-btn-primary sf-btn-block sf-btn-lg mt-5">
                            <span x-show="!placing">{{ __('storefront.place_order') }}</span>
                            <span x-show="placing" x-cloak>{{ __('storefront.loading') }}</span>
                        </button>

                        <a href="{{ route('storefront.cart') }}"
                           class="flex items-center justify-center min-h-10 mt-2 text-sm font-semibold text-[color:var(--sf-text-soft)] hover:text-brand-600 transition-colors">
                            {{ __('storefront.back_to_cart') }}
                        </a>
                    </div>
                </div>
            </form>
        </template>
    </div>

    {{--
        منطق جلسة الإتمام. المسارات والترويسات وتسلسل PATCH ← place كما هي.
        الإضافة: المدينة والمنطقة ضمن النموذج، و`sync()` تُرسل الجلسة للخلفية
        فتعيد الرسوم والإجمالي. **لا تُحسب الرسوم هنا إطلاقًا** — تُعرَض كما وردت.
    --}}
    <script>
        function storefrontCheckout() {
            const API = '/api/v1/store';
            return {
                sessionId: null,
                empty: false,
                placing: false,
                error: null,
                order: null,
                areas: @js($areas),
                totals: { subtotal: 0, delivery_fee: 0, total: 0 },
                form: {
                    customer_name: '', customer_phone: '',
                    shipping_address: '', city_id: '', area_id: '', payment_method_code: 'cod',
                },

                async init() {
                    try {
                        const res = await fetch(`${API}/checkout`, { method: 'POST', headers: window.StorefrontIdentity.headers() });
                        if (res.status === 422) { this.empty = true; return; }
                        if (!res.ok) throw new Error('start');
                        const data = (await res.json()).data;
                        this.sessionId = data.id;
                        this.applyTotals(data);
                        window.StorefrontAnalytics.track('CheckoutStarted', { session: this.sessionId });
                    } catch (e) { this.error = '{{ __('storefront.error') }}'; }
                },

                money(v) {
                    return `${Number(v || 0).toFixed(2)} {{ __('storefront.currency') }}`;
                },

                areasOf(cityId) {
                    return cityId ? this.areas.filter(a => Number(a.city_id) === Number(cityId)) : [];
                },

                // تغيير المدينة يُبطل منطقة لم تعد تتبعها، ثم يُزامن مع الخلفية.
                pickCity() {
                    this.form.area_id = '';
                    this.sync();
                },

                applyTotals(data) {
                    if (data && data.cart) {
                        this.totals = {
                            subtotal: data.cart.subtotal ?? 0,
                            delivery_fee: data.cart.delivery_fee ?? 0,
                            total: data.cart.total ?? 0,
                        };
                    }
                },

                /** يحفظ الجلسة ويقرأ الرسوم المحسوبة في الخلفية. */
                async sync() {
                    if (!this.sessionId) return;
                    try {
                        const res = await fetch(`${API}/checkout/${this.sessionId}`, {
                            method: 'PATCH',
                            headers: window.StorefrontIdentity.headers(),
                            body: JSON.stringify(this.form),
                        });
                        if (res.ok) this.applyTotals((await res.json()).data);
                    } catch (e) { /* تجاهل — تُعاد المزامنة عند الإتمام */ }
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

    {{-- توصيات في الإتمام (Phase 6 / ADR-045) --}}
    <x-storefront.section :title="__('storefront.customers_also_bought')" :items="$recommendations" recoType="best_seller" placement="checkout" />
</x-storefront.layout>
