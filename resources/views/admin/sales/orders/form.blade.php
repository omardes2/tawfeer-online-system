<x-app-layout :title="__('طلب بيع جديد')">
    <x-admin.header
        :title="__('طلب بيع جديد')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الطلبات') => route('admin.sales.orders.index'), __('جديد') => null]" />

    <x-admin.flash />

    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <form method="POST" action="{{ route('admin.sales.orders.store') }}"
          x-data="orderForm(@js($products), @js($areas), @js($cityRates), @js($cities))"
          @submit="if (rows.length === 0) { $event.preventDefault(); alert('{{ __('أضف صنفًا واحدًا على الأقل.') }}'); }"
          class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf

        {{-- مستودع واحد افتراضي — مخفيّ (النظام أحادي المستودع). --}}
        @if ($warehouse)<input type="hidden" name="warehouse" value="{{ $warehouse->uuid }}" />@endif

        {{-- ————— العمود الأيمن: بيانات العميل والتوصيل ————— --}}
        <div class="lg:col-span-2 space-y-6">
            <x-admin.form-section :title="__('بيانات العميل والتوصيل')" :cols="2">
                <x-admin.field :label="__('اسم العميل')" name="customer_name" :required="true">
                    <input type="text" name="customer_name" value="{{ old('customer_name') }}" required
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>

                <x-admin.field :label="__('رقم الهاتف')" name="customer_phone" :required="true">
                    <input type="text" name="customer_phone" value="{{ old('customer_phone') }}" required inputmode="tel"
                           placeholder="0599123456"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('رقم فلسطيني من 10 أرقام (يبدأ بـ 05).') }}</p>
                </x-admin.field>

                <x-admin.field :label="__('المدينة')" name="city_id" :required="true">
                    {{-- قائمة مدن قابلة للبحث (combobox): مربّع بحث يُصفّي المدن ويختار --}}
                    <div class="relative" @click.outside="cityOpen = false">
                        <div class="relative">
                            <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                            </span>
                            <input type="text" x-model="citySearch" @focus="cityOpen = true" @input="cityOpen = true"
                                   autocomplete="off" placeholder="{{ __('ابحث واختر المدينة…') }}"
                                   class="w-full ps-9 pe-3 rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        </div>
                        <input type="hidden" name="city_id" :value="cityId" />
                        <div x-show="cityOpen" x-transition x-cloak
                             class="absolute z-30 mt-1 w-full max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                            <template x-for="c in filteredCities" :key="c.id">
                                <button type="button" @click="selectCity(c)"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-start hover:bg-emerald-50"
                                        :class="Number(c.id) === Number(cityId) ? 'bg-emerald-50 text-emerald-700 font-medium' : 'text-gray-700'">
                                    <span x-text="c.name"></span>
                                </button>
                            </template>
                            <template x-if="filteredCities.length === 0">
                                <p class="px-3 py-2 text-sm text-gray-400">{{ __('لا نتائج مطابقة') }}</p>
                            </template>
                        </div>
                    </div>
                </x-admin.field>

                <x-admin.field :label="__('المنطقة')" name="area_id" :required="true">
                    {{-- قائمة مناطق قابلة للبحث (تُصفّى حسب المدينة المختارة) --}}
                    <div class="relative" @click.outside="areaOpen = false">
                        <div class="relative">
                            <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                            </span>
                            <input type="text" x-model="areaSearch" @focus="cityId && (areaOpen = true)" @input="areaOpen = true"
                                   autocomplete="off" :disabled="!cityId"
                                   placeholder="{{ __('ابحث واختر المنطقة…') }}"
                                   class="w-full ps-9 pe-3 rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-gray-400" />
                        </div>
                        <input type="hidden" name="area_id" :value="areaId" />
                        <div x-show="areaOpen" x-transition x-cloak
                             class="absolute z-30 mt-1 w-full max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                            <template x-for="a in filteredAreas" :key="a.id">
                                <button type="button" @click="selectArea(a)"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-start hover:bg-emerald-50"
                                        :class="Number(a.id) === Number(areaId) ? 'bg-emerald-50 text-emerald-700 font-medium' : 'text-gray-700'">
                                    <span x-text="a.name"></span>
                                </button>
                            </template>
                            <template x-if="filteredAreas.length === 0">
                                <p class="px-3 py-2 text-sm text-gray-400">{{ __('لا نتائج مطابقة') }}</p>
                            </template>
                        </div>
                    </div>
                    <template x-if="cityId && areasForCity.length === 0">
                        <p class="mt-1 text-xs text-gray-400">{{ __('لا مناطق مسجّلة لهذه المدينة.') }}</p>
                    </template>
                </x-admin.field>

                <div class="md:col-span-2">
                    <x-admin.field :label="__('العنوان التفصيلي')" name="shipping_address" :required="true">
                        <textarea name="shipping_address" rows="2" required
                                  class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500"
                                  placeholder="{{ __('الشارع، رقم البناية، أقرب معلم…') }}">{{ old('shipping_address') }}</textarea>
                    </x-admin.field>
                </div>
            </x-admin.form-section>

            {{-- ————— الأصناف: بحث بالاسم + صورة مصغّرة ————— --}}
            <x-admin.form-section :title="__('الأصناف')">
                <div class="relative" @click.outside="showResults = false">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ابحث عن صنف بالاسم') }}</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.2-5.2m2.2-5.3a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/></svg>
                        </span>
                        <input type="text" x-model="query" @focus="showResults = true"
                               placeholder="{{ __('اكتب اسم المنتج…') }}"
                               class="w-full rounded-lg border-gray-300 ps-10 focus:border-emerald-500 focus:ring-emerald-500" />
                    </div>

                    {{-- نتائج البحث مع صورة مصغّرة بجانب الاسم --}}
                    <div x-show="showResults && filteredProducts.length"
                         x-transition
                         class="absolute z-20 mt-1 w-full max-h-72 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                        <template x-for="p in filteredProducts" :key="p.variant">
                            <button type="button" @click="addProduct(p)"
                                    class="w-full flex items-center gap-3 px-3 py-2 hover:bg-emerald-50 text-start">
                                <span class="w-10 h-10 shrink-0 rounded-md bg-gray-100 overflow-hidden grid place-items-center">
                                    <template x-if="p.image">
                                        <img :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
                                    </template>
                                    <template x-if="!p.image">
                                        <svg class="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                                    </template>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-800 truncate" x-text="p.name"></span>
                                        <span class="shrink-0 text-[11px] px-1.5 py-0.5 rounded-md tabular-nums"
                                              :class="(Number(p.available) > 0) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'"
                                              x-text="'{{ __('المتوفر') }}: ' + Number(p.available ?? 0)"></span>
                                    </span>
                                    <span class="block text-xs text-gray-400" x-text="p.sku"></span>
                                </span>
                                <span class="text-sm font-semibold text-emerald-600 tabular-nums" x-text="p.price.toFixed(2) + ' {{ $sym }}'"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- بنود الطلب --}}
                <div class="mt-2">
                    <template x-if="rows.length === 0">
                        <div class="text-center py-8 text-sm text-gray-400 border border-dashed border-gray-200 rounded-lg">
                            {{ __('لم تُضف أصناف بعد — ابحث أعلاه وأضف.') }}
                        </div>
                    </template>

                    <div class="divide-y divide-gray-100" x-show="rows.length">
                        <template x-for="(row, i) in rows" :key="row.variant">
                            <div class="flex items-center gap-3 py-3">
                                <span class="w-11 h-11 shrink-0 rounded-md bg-gray-100 overflow-hidden grid place-items-center">
                                    <template x-if="row.image"><img :src="row.image" :alt="row.name" class="w-full h-full object-cover" /></template>
                                    <template x-if="!row.image"><span class="text-gray-300 text-xs">—</span></template>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-800 truncate" x-text="row.name"></p>
                                    <p class="text-xs text-gray-400" x-text="row.sku"></p>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ __('السعر') }}</label>
                                    <input type="number" step="0.01" min="0" x-model.number="row.unit_price"
                                           class="w-24 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ __('الكمية') }}</label>
                                    <input type="number" step="1" min="1" x-model.number="row.qty"
                                           class="w-20 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                                </div>
                                <div class="w-24 text-start">
                                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ __('الإجمالي') }}</label>
                                    <span class="text-sm font-semibold text-gray-900 tabular-nums" x-text="lineTotal(row).toFixed(2)"></span>
                                </div>
                                <button type="button" @click="removeRow(i)" class="text-rose-400 hover:text-rose-600 shrink-0 mt-4">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>

                                {{-- حقول الإرسال المخفية --}}
                                <input type="hidden" :name="`items[${i}][variant]`" :value="row.variant" />
                                <input type="hidden" :name="`items[${i}][qty]`" :value="row.qty" />
                                <input type="hidden" :name="`items[${i}][unit_price]`" :value="row.unit_price" />
                                <input type="hidden" :name="`items[${i}][discount]`" value="0" />
                            </div>
                        </template>
                    </div>
                    @error('items')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            </x-admin.form-section>
        </div>

        {{-- ————— العمود الأيسر: مرتجع/ملاحظات + ملخّص ————— --}}
        <div class="space-y-6">
            <x-admin.form-section :title="__('خيارات إضافية')">
                <label class="flex items-center justify-between gap-3 cursor-pointer">
                    <span class="text-sm font-medium text-gray-700">{{ __('هل يوجد مرتجع؟') }}</span>
                    <span class="relative inline-flex items-center">
                        <input type="checkbox" name="has_return" value="1" x-model="hasReturn" class="peer sr-only" />
                        <span class="w-11 h-6 rounded-full bg-gray-200 peer-checked:bg-emerald-500 transition-colors"></span>
                        <span class="absolute start-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-[-1.25rem] rtl:peer-checked:translate-x-[1.25rem]"></span>
                    </span>
                </label>

                <div x-show="hasReturn" x-transition x-cloak class="space-y-3">
                    {{-- اختيار الأصناف المرتجعة --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الأصناف المرتجعة') }}</label>
                        <div class="relative">
                            <input type="text" x-model="returnQuery" @focus="showReturnResults = true" @click.outside="showReturnResults = false"
                                   placeholder="{{ __('ابحث عن صنف لإضافته للمرتجع...') }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            <div x-show="showReturnResults && filteredReturnProducts.length" x-cloak
                                 class="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                                <template x-for="p in filteredReturnProducts" :key="p.variant">
                                    <button type="button" @click="addReturn(p)"
                                            class="w-full flex items-center gap-2 px-3 py-2 hover:bg-emerald-50 text-start">
                                        <span class="text-sm text-gray-800 truncate" x-text="p.name"></span>
                                        <span class="text-xs text-gray-400" x-text="p.sku"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- جدول الأصناف المرتجعة المختارة --}}
                    <div x-show="returnRows.length" class="rounded-lg border border-gray-100 divide-y divide-gray-100">
                        <template x-for="(r, i) in returnRows" :key="r.variant">
                            <div class="flex items-center gap-2 px-3 py-2">
                                <span class="flex-1 min-w-0 text-sm text-gray-800 truncate" x-text="r.name"></span>
                                <label class="text-xs text-gray-400">{{ __('الكمية') }}</label>
                                <input type="number" min="1" step="1" x-model.number="r.qty" class="w-16 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                <button type="button" @click="removeReturn(i)" class="text-rose-500 hover:text-rose-700" title="{{ __('إزالة') }}">&times;</button>
                            </div>
                        </template>
                    </div>

                    {{-- ملاحظات إضافية على المرتجع --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ملاحظات المرتجع') }}</label>
                        <textarea x-model="returnFreeNote" rows="2"
                                  placeholder="{{ __('سبب الإرجاع أو أي تفاصيل...') }}"
                                  class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                    </div>

                    {{-- ما سيُحفظ فعليًا (ملخّص المرتجع + الملاحظات) --}}
                    <input type="hidden" name="return_notes" :value="composedReturnNotes" />
                    <template x-if="composedReturnNotes.trim()">
                        <p class="text-xs text-gray-500 bg-gray-50 rounded-md p-2 whitespace-pre-line" x-text="composedReturnNotes"></p>
                    </template>
                </div>

                <x-admin.field :label="__('ملاحظات الطلب')" name="notes">
                    <textarea name="notes" rows="2"
                              class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes') }}</textarea>
                </x-admin.field>
            </x-admin.form-section>

            <x-admin.form-section :title="__('ملخّص الطلب')">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('الإجمالي الفرعي') }}</dt>
                        <dd class="font-medium text-gray-900 tabular-nums"><span x-text="subtotal.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('رسوم التوصيل') }}</dt>
                        <dd class="font-medium text-gray-900 tabular-nums">
                            <span x-text="deliveryFee.toFixed(2)"></span> {{ $sym }}
                            <template x-if="cityId && !(cityId in cityRates)">
                                <span class="block text-[11px] text-amber-500">{{ __('لا سعر مضبوط لهذه المدينة') }}</span>
                            </template>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                        <dt class="font-semibold text-gray-900">{{ __('الإجمالي') }}</dt>
                        <dd class="text-lg font-bold text-emerald-600 tabular-nums"><span x-text="total.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                </dl>

                <div class="flex flex-col gap-2 pt-2">
                    <button type="submit" class="btn-primary w-full justify-center">{{ __('حفظ كمسودّة') }}</button>
                    <a href="{{ route('admin.sales.orders.index') }}" class="btn-secondary w-full justify-center">{{ __('إلغاء') }}</a>
                </div>
                <p class="text-xs text-gray-400 text-center">{{ __('يُرسَل الطلب لشركة التوصيل عند تأكيده.') }}</p>
            </x-admin.form-section>
        </div>
    </form>

    @push('scripts')
        <script>
            function orderForm(products, areas, cityRates, cities) {
                return {
                    products: products || [],
                    areas: areas || [],
                    cities: cities || [],
                    cityRates: cityRates || {},
                    query: '',
                    showResults: false,
                    cityId: @js(old('city_id') ? (int) old('city_id') : ''),
                    areaId: @js(old('area_id') ? (int) old('area_id') : ''),
                    citySearch: '',
                    cityOpen: false,
                    areaSearch: '',
                    areaOpen: false,

                    // تهيئة نصوص البحث بأسماء المدينة/المنطقة المختارة سابقًا (عند خطأ تحقّق).
                    init() {
                        const sc = this.cities.find(c => Number(c.id) === Number(this.cityId));
                        if (sc) this.citySearch = sc.name;
                        const sa = this.areas.find(a => Number(a.id) === Number(this.areaId));
                        if (sa) this.areaSearch = sa.name;
                    },

                    get filteredCities() {
                        const q = (this.citySearch || '').trim().toLowerCase();
                        const sel = this.cities.find(c => Number(c.id) === Number(this.cityId));
                        // إن كان النص مطابقًا لاسم المدينة المختارة، اعرض الكل (لتسهيل التغيير).
                        if (q === '' || (sel && q === sel.name.toLowerCase())) return this.cities;
                        return this.cities.filter(c => (c.name || '').toLowerCase().includes(q));
                    },

                    selectCity(c) {
                        this.cityId = Number(c.id);
                        this.citySearch = c.name;
                        this.areaId = '';
                        this.areaSearch = '';
                        this.cityOpen = false;
                    },

                    get filteredAreas() {
                        const list = this.areasForCity;
                        const q = (this.areaSearch || '').trim().toLowerCase();
                        const sel = list.find(a => Number(a.id) === Number(this.areaId));
                        if (q === '' || (sel && q === sel.name.toLowerCase())) return list;
                        return list.filter(a => (a.name || '').toLowerCase().includes(q));
                    },

                    selectArea(a) {
                        this.areaId = Number(a.id);
                        this.areaSearch = a.name;
                        this.areaOpen = false;
                    },
                    hasReturn: @js((bool) old('has_return')),
                    rows: [],
                    returnRows: [],
                    returnQuery: '',
                    showReturnResults: false,
                    returnFreeNote: @js(old('return_notes') ?? ''),

                    get filteredReturnProducts() {
                        const q = this.returnQuery.trim().toLowerCase();
                        const list = q === ''
                            ? this.products
                            : this.products.filter(p =>
                                (p.name || '').toLowerCase().includes(q) ||
                                (p.sku || '').toLowerCase().includes(q));
                        return list.slice(0, 30);
                    },
                    get returnSummary() {
                        return this.returnRows
                            .map(r => `${r.name} ×${Number(r.qty) || 1}`)
                            .join('، ');
                    },
                    get composedReturnNotes() {
                        const parts = [];
                        if (this.returnRows.length) parts.push('{{ __('الأصناف المرتجعة') }}: ' + this.returnSummary);
                        if (this.returnFreeNote.trim()) parts.push(this.returnFreeNote.trim());
                        return parts.join('\n');
                    },
                    addReturn(p) {
                        const existing = this.returnRows.find(r => r.variant === p.variant);
                        if (existing) {
                            existing.qty = (Number(existing.qty) || 0) + 1;
                        } else {
                            this.returnRows.push({ variant: p.variant, name: p.name, sku: p.sku, qty: 1 });
                        }
                        this.returnQuery = '';
                        this.showReturnResults = false;
                    },
                    removeReturn(i) {
                        this.returnRows.splice(i, 1);
                    },

                    get filteredProducts() {
                        const q = this.query.trim().toLowerCase();
                        const list = q === ''
                            ? this.products
                            : this.products.filter(p =>
                                (p.name || '').toLowerCase().includes(q) ||
                                (p.sku || '').toLowerCase().includes(q));
                        return list.slice(0, 30);
                    },
                    get areasForCity() {
                        if (!this.cityId) return [];
                        return this.areas.filter(a => Number(a.city_id) === Number(this.cityId));
                    },
                    get deliveryFee() {
                        return Number(this.cityRates[this.cityId] || 0);
                    },
                    get subtotal() {
                        return this.rows.reduce((s, r) => s + this.lineTotal(r), 0);
                    },
                    get total() {
                        return this.subtotal + this.deliveryFee;
                    },
                    lineTotal(r) {
                        return (Number(r.qty) || 0) * (Number(r.unit_price) || 0);
                    },
                    addProduct(p) {
                        const existing = this.rows.find(r => r.variant === p.variant);
                        if (existing) {
                            existing.qty = (Number(existing.qty) || 0) + 1;
                        } else {
                            this.rows.push({
                                variant: p.variant, name: p.name, sku: p.sku,
                                image: p.image, unit_price: p.price, qty: 1,
                            });
                        }
                        this.query = '';
                        this.showResults = false;
                    },
                    removeRow(i) {
                        this.rows.splice(i, 1);
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
