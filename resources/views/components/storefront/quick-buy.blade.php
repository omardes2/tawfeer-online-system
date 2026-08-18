@props([
    // معرّف المتغيّر: نصّ ثابت للمنتج البسيط، أو تعبير JS للمنتج ذي الخيارات.
    'variant' => null,
    'variantExpr' => null,
    'enabledExpr' => 'true',
    'cities' => [],
    'areas' => [],
    // مضغوط: داخل الشريط اللاصق على الجوّال — بلا عرضٍ كامل ولا هامش علوي.
    'compact' => false,
    // يُخفى على الجوّال حين يحمله الشريط اللاصق بدلًا منه (فلا يتكرّر الزرّ).
    'hideOnMobile' => false,
    /*
    | يستمع لحدث الفتح العامّ. **نسخةٌ واحدة في الصفحة تفعل** — الصفحة تحمل
    | أكثر من لوح (المتغيّرات، المنتج البسيط، الشريط اللاصق)، ولو استمعت كلُّها
    | لانفتحت ثلاثة ألواحٍ فوق بعضها عند أول ضغطة.
    */
    'listens' => false,
    // بلا زرّ: يحمله شيءٌ آخر (بطاقة العروض) ويفتحه بالحدث.
    'triggerless' => false,
])

{{--
    ⚠️ Protected Delivery Integration — Do Not Modify.

    «شراء الآن»: **شكلٌ آخر حول مسار الإتمام القائم، لا مسار جديد**. لا نقطة API
    مستحدثة ولا حساب رسوم في الواجهة ولا عقد مختلف. يُعاد استعمال ما يلي حرفيًّا:

      • إضافة الصنف: مخزن السلة `$store.cart.add()` ← `POST /api/v1/store/cart/items`
      • تسلسل الإتمام: `POST /checkout` ← `PATCH /checkout/{id}` ← `POST /checkout/{id}/place`
      • مفاتيح النموذج: customer_name / customer_phone / shipping_address /
        city_id / area_id / payment_method_code
      • الهوية: `window.StorefrontIdentity.headers()`
      • **رسوم التوصيل تأتي من استجابة الـPATCH وحدها** — لا تُحسب هنا إطلاقًا.

    وجلسة الإتمام تُبنى من السلة لا من صنف، فالطلب يشمل كل ما فيها. ولذلك يُعرَض
    ملخّصها كاملًا ويُقال صراحةً حين تحمل غير هذا الصنف — الأسرع ليس أن يُفاجأ
    الزبون بطلبٍ لم يقصده، ولا أن تُمحى سلّته بلا استئذان.
--}}
@php
    $variantJs = $variantExpr ?: "'".$variant."'";
@endphp

<div x-data="quickBuy(@js($areas))" @keydown.escape.window="close()"
     @if ($listens) @quick-buy:open.window="open($event.detail.variant, $event.detail)" @endif
     @class(['contents' => $compact, 'hidden lg:block' => $hideOnMobile])>

    {{--
        `@class` واحدة لا اثنتان: كلٌّ منهما تُصيّر السمة `class` كاملةً، والمتصفّح
        يأخذ الأولى ويُهمل الثانية — فخرج الزرّ بلا أصنافٍ إطلاقًا: نصٌّ عارٍ
        وأيقونةٌ في سطرٍ وحدها بدل زرّ. `hidden` مدموجةٌ هنا وتغلب `display` غيرها.
    --}}
    <button type="button" @click="open({!! $variantJs !!})"
            :disabled="!({!! $enabledExpr !!}) || !({!! $variantJs !!}) || busy"
            @class([
                'hidden' => $triggerless,
                'sf-btn-primary whitespace-nowrap shrink-0 min-h-10 !px-3 gap-1.5 text-[13px]' => $compact,
                'sf-btn-outline sf-btn-block sf-btn-lg mt-2' => ! $compact,
            ])>
        <x-storefront.icon name="bolt" :class="$compact ? 'w-4 h-4' : 'w-5 h-5'" />
        {{ __('storefront.buy_now') }}
    </button>

    {{-- اللوح المنزلق: مُعلَّق على body فلا يحبسه أيّ عنصر ذو overflow --}}
    <template x-teleport="body">
        <div x-show="shown" x-cloak class="fixed inset-0 z-[60]" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/50" @click="close()" x-transition.opacity></div>

            <div class="absolute inset-x-0 bottom-0 sm:inset-y-0 sm:end-0 sm:inset-x-auto sm:w-[26rem]
                        max-h-[92vh] sm:max-h-none overflow-y-auto bg-[color:var(--sf-bg)] shadow-2xl
                        rounded-t-2xl sm:rounded-none"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full"
                 x-transition:enter-end="translate-y-0 sm:translate-x-0">

                <div class="sticky top-0 flex items-center justify-between gap-3 px-4 py-3
                            bg-[color:var(--sf-bg)] border-b border-[color:var(--sf-border)]">
                    <h2 class="font-bold text-[color:var(--sf-text)]" x-text="order ? '{{ __('storefront.order_placed') }}' : '{{ __('storefront.quick_checkout') }}'"></h2>
                    <button type="button" @click="close()" class="p-2 -m-2 text-[color:var(--sf-text-soft)]"
                            aria-label="{{ __('storefront.close') }}">
                        <x-storefront.icon name="close" class="w-5 h-5" />
                    </button>
                </div>

                {{-- تأكيد الطلب — نفس ما تعرضه صفحة الإتمام بعد النجاح --}}
                <template x-if="order">
                    <div class="p-5 text-center">
                        <span class="mx-auto grid place-items-center w-14 h-14 rounded-full bg-brand-50 text-brand-600">
                            <x-storefront.icon name="check-circle" class="w-8 h-8" />
                        </span>
                        <p class="mt-3 font-bold text-[color:var(--sf-text)]">{{ __('storefront.order_placed') }}</p>
                        <p class="mt-1 text-sm text-[color:var(--sf-text-soft)]">
                            {{ __('storefront.order_number') }}:
                            <span class="font-bold tabular-nums" dir="ltr" x-text="order.number"></span>
                        </p>
                        <a :href="'{{ route('storefront.shop') }}'" class="sf-btn-outline sf-btn-block mt-5">
                            {{ __('storefront.continue_shopping') }}
                        </a>
                    </div>
                </template>

                <template x-if="!order">
                    <form @submit.prevent="place()" class="p-4 space-y-4">

                        {{-- الطلب يشمل السلة كلّها: يُقال صراحةً حين تحمل غير هذا الصنف --}}
                        <div x-show="otherItems > 0" x-cloak
                             class="rounded-lg px-3 py-2 text-xs"
                             style="background:#FFF7E6;color:#92400E">
                            <span x-text="'{{ __('storefront.quick_buy_cart_notice') }}'.replace(':count', cartCount)"></span>
                            <a href="{{ route('storefront.cart') }}" class="font-bold underline">{{ __('storefront.view_cart') }}</a>
                        </div>

                        <div>
                            <label for="q-name" class="sf-label">{{ __('storefront.customer_name') }}</label>
                            <input id="q-name" x-model="form.customer_name" required class="sf-input">
                        </div>

                        <div>
                            <label for="q-phone" class="sf-label">{{ __('storefront.customer_phone') }}</label>
                            <input id="q-phone" x-model="form.customer_phone" required inputmode="tel" class="sf-input" dir="ltr">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="q-city" class="sf-label">{{ __('storefront.city') }}</label>
                                <select id="q-city" x-model.number="form.city_id" @change="pickCity()" required class="sf-select">
                                    <option value="">{{ __('storefront.choose_city') }}</option>
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="q-area" class="sf-label">{{ __('storefront.area') }}</label>
                                <select id="q-area" x-model.number="form.area_id" @change="sync()"
                                        :disabled="!form.city_id" class="sf-select">
                                    <option value="">{{ __('storefront.choose_area') }}</option>
                                    <template x-for="a in areasOf(form.city_id)" :key="a.id">
                                        <option :value="a.id" x-text="a.name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="q-address" class="sf-label">{{ __('storefront.address_details') }}</label>
                            <textarea id="q-address" x-model="form.shipping_address" required rows="2" class="sf-textarea"
                                      placeholder="{{ __('storefront.address_placeholder') }}"></textarea>
                        </div>

                        {{-- الدفع عند الاستلام: القيمة نفسها `cod`، واسم الحقل مختلف
                             عن صفحة الإتمام عمدًا كي لا يتصادم راديو مع راديو. --}}
                        <label class="flex items-center gap-3 rounded-xl border-2 border-brand-600 bg-brand-50 p-3">
                            <input type="radio" name="qpm" value="cod" x-model="form.payment_method_code"
                                   class="text-brand-600 focus:ring-brand-500 w-5 h-5">
                            <span class="min-w-0">
                                <span class="block font-semibold text-sm text-[color:var(--sf-text)]">{{ __('storefront.cod') }}</span>
                                <span class="block text-xs text-[color:var(--sf-text-soft)]">{{ __('storefront.cod_hint') }}</span>
                            </span>
                        </label>

                        {{-- كل الأرقام من الخلفية — لا معادلة تسعير هنا --}}
                        <div class="rounded-xl border border-[color:var(--sf-border)] p-3 text-sm">
                            <div class="flex items-center justify-between py-1 text-[color:var(--sf-text-soft)]">
                                <span>{{ __('storefront.subtotal') }}</span>
                                <span class="font-bold tabular-nums text-[color:var(--sf-text)]" x-text="money(totals.subtotal)"></span>
                            </div>
                            <div class="flex items-center justify-between py-1 text-[color:var(--sf-text-soft)]">
                                <span>{{ __('storefront.delivery_fee') }}</span>
                                <span x-show="!form.city_id" class="text-xs">{{ __('storefront.choose_city_for_fee') }}</span>
                                <span x-show="form.city_id" x-cloak class="font-bold tabular-nums text-[color:var(--sf-text)]"
                                      x-text="totals.delivery_fee > 0 ? money(totals.delivery_fee) : '{{ __('storefront.free') }}'"></span>
                            </div>
                            <div class="flex items-center justify-between pt-2 mt-1 border-t border-[color:var(--sf-border)]">
                                <span class="font-bold text-[color:var(--sf-text)]">{{ __('storefront.total') }}</span>
                                <span class="sf-price text-lg" x-text="money(totals.total)"></span>
                            </div>
                        </div>

                        <template x-if="error">
                            <p class="rounded-lg px-3 py-2 text-sm" style="background:#FBE9E7;color:var(--sf-danger)" x-text="error"></p>
                        </template>

                        <button type="submit" :disabled="placing || !sessionId" class="sf-btn-primary sf-btn-block sf-btn-lg">
                            <span x-show="!placing">{{ __('storefront.place_order') }}</span>
                            <span x-show="placing" x-cloak>{{ __('storefront.loading') }}</span>
                        </button>
                    </form>
                </template>
            </div>
        </div>
    </template>
</div>
