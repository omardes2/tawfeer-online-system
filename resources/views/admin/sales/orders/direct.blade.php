<x-app-layout :title="__('مبيعات مباشرة')">
    <x-admin.header
        :title="__('مبيعات مباشرة')"
        :description="__('بيع فوري من المستودع — تُخصم الكميات مباشرة دون إرسال لشركة التوصيل.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المبيعات') => route('admin.sales.orders.index'), __('مبيعات مباشرة') => null]" />

    @php($sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <x-admin.flash />

    <form method="POST" action="{{ route('admin.sales.orders.direct.store') }}"
          x-data="directSale(@js($products), @js($customers))"
          @submit="if (rows.length === 0) { $event.preventDefault(); alert('{{ __('أضف صنفًا واحدًا على الأقل.') }}'); }"
          class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf

        {{-- العمود الأيمن: العميل + الأصناف --}}
        <div class="lg:col-span-2 space-y-6">
            <x-admin.form-section :title="__('بيانات العميل')">
                {{-- تبديل: كتابة اسم جديد أو اختيار عميل مسجّل --}}
                <div class="inline-flex rounded-lg border border-gray-200 p-0.5 mb-4 text-sm">
                    <button type="button" @click="custMode = 'new'; clearCustomer()"
                            :class="custMode === 'new' ? 'bg-emerald-600 text-white' : 'text-gray-600'"
                            class="px-3 py-1.5 rounded-md">{{ __('عميل جديد') }}</button>
                    <button type="button" @click="custMode = 'existing'"
                            :class="custMode === 'existing' ? 'bg-emerald-600 text-white' : 'text-gray-600'"
                            class="px-3 py-1.5 rounded-md">{{ __('عميل مسجّل') }}</button>
                </div>

                {{-- وضع: عميل جديد (كتابة) --}}
                <div x-show="custMode === 'new'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-admin.field :label="__('اسم العميل')" name="customer_name" :required="true">
                        <input type="text" name="customer_name" x-model="customerName"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('رقم الهاتف (اختياري)')" name="customer_phone">
                        <input type="text" name="customer_phone" x-model="customerPhone" inputmode="tel"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                </div>

                {{-- وضع: عميل مسجّل (اختيار من القائمة) --}}
                <div x-show="custMode === 'existing'" x-cloak>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('اختر عميلًا') }}</label>
                    <template x-if="!selectedCustomer">
                        <div class="relative">
                            <input type="text" x-model="custQuery" @focus="showCustResults = true" @click.outside="showCustResults = false"
                                   placeholder="{{ __('ابحث بالاسم أو الهاتف...') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <div x-show="showCustResults && filteredCustomers.length" x-cloak
                                 class="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                                <template x-for="c in filteredCustomers" :key="c.uuid">
                                    <button type="button" @click="pickCustomer(c)"
                                            class="w-full flex items-center justify-between gap-2 px-3 py-2 hover:bg-emerald-50 text-start">
                                        <span class="text-sm text-gray-800 truncate" x-text="c.name"></span>
                                        <span class="text-xs text-gray-400" x-text="c.phone" dir="ltr"></span>
                                    </button>
                                </template>
                            </div>
                            <template x-if="custQuery.trim() && filteredCustomers.length === 0">
                                <p class="mt-1 text-xs text-gray-400">{{ __('لا عميل مطابق. جرّب «عميل جديد».') }}</p>
                            </template>
                        </div>
                    </template>
                    <template x-if="selectedCustomer">
                        <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50/50 px-3 py-2">
                            <div>
                                <span class="text-sm font-medium text-gray-800" x-text="selectedCustomer.name"></span>
                                <span class="block text-xs text-gray-500" dir="ltr" x-text="selectedCustomer.phone"></span>
                            </div>
                            <button type="button" @click="clearCustomer()" class="text-rose-500 hover:text-rose-700 text-sm">{{ __('تغيير') }}</button>
                        </div>
                    </template>
                    <input type="hidden" name="customer" :value="selectedCustomer?.uuid || ''" />
                </div>
                @error('customer_name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </x-admin.form-section>

            <x-admin.form-section :title="__('الأصناف')">
                {{-- بحث عن صنف --}}
                <div class="relative">
                    <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                    </span>
                    <input type="text" x-model="query" @focus="showResults = true" @click.outside="showResults = false"
                           placeholder="{{ __('ابحث عن صنف بالاسم أو الكود...') }}"
                           class="w-full ps-10 rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    <div x-show="showResults && filteredProducts.length" x-cloak
                         class="absolute z-20 mt-1 w-full max-h-64 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                        <template x-for="p in filteredProducts" :key="p.variant">
                            {{-- الاسم في سطره وحده ليُقرأ كاملًا، والكود والسعر تحته --}}
                            <button type="button" @click="addProduct(p)"
                                    class="w-full flex items-start gap-3 px-3 py-2.5 hover:bg-emerald-50 text-start border-b border-gray-50 last:border-0">
                                <span class="w-9 h-9 shrink-0 rounded-md bg-gray-100 overflow-hidden grid place-items-center">
                                    <template x-if="p.image"><img :src="p.image" :alt="p.name" class="w-full h-full object-cover" /></template>
                                    <template x-if="!p.image"><span class="text-gray-300 text-xs">—</span></template>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[13px] font-medium text-gray-800 leading-snug break-words" x-text="p.name"></span>
                                    <span class="mt-1 flex items-center flex-wrap gap-x-2 gap-y-1">
                                        <span class="text-[11px] text-gray-400 font-mono" x-text="p.sku"></span>
                                        <span class="ms-auto text-sm font-semibold text-emerald-600 tabular-nums"
                                              x-text="Number(p.price).toFixed(2) + ' {{ $sym }}'"></span>
                                    </span>
                                </span>
                            </button>
                        </template>
                    </div>
                </div>

                <template x-if="rows.length === 0">
                    <p class="mt-4 text-sm text-gray-400 text-center py-6">{{ __('لم تُضف أصناف بعد.') }}</p>
                </template>

                <div class="divide-y divide-gray-100 mt-2" x-show="rows.length">
                    {{-- ترويسة الأعمدة: اسم الصنف ثم السعر فالكمية فالإجمالي --}}
                    <div class="flex items-center gap-3 pb-1 text-xs font-medium text-gray-400">
                        <span class="w-11 shrink-0"></span>
                        <div class="min-w-0 flex-1">{{ __('الصنف') }}</div>
                        <div class="w-24">{{ __('السعر') }}</div>
                        <div class="w-20">{{ __('الكمية') }}</div>
                        <span class="w-24 text-start">{{ __('الإجمالي') }}</span>
                        <span class="w-4"></span>
                    </div>
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
                            <div class="w-24">
                                <input type="number" step="0.01" min="0" x-model.number="row.unit_price"
                                       class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            </div>
                            <div class="w-20">
                                <input type="number" step="1" min="1" x-model.number="row.qty"
                                       class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            </div>
                            <span class="w-24 text-start text-sm font-semibold text-gray-900 tabular-nums" x-text="lineTotal(row).toFixed(2)"></span>
                            <button type="button" @click="removeRow(i)" class="text-rose-500 hover:text-rose-700">&times;</button>

                            <input type="hidden" :name="`items[${i}][variant]`" :value="row.variant" />
                            <input type="hidden" :name="`items[${i}][qty]`" :value="row.qty" />
                            <input type="hidden" :name="`items[${i}][unit_price]`" :value="row.unit_price" />
                            <input type="hidden" :name="`items[${i}][discount]`" value="0" />
                        </div>
                    </template>
                </div>
                @error('items')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
            </x-admin.form-section>
        </div>

        {{-- العمود الأيسر: ملاحظات + ملخّص --}}
        <div class="space-y-6">
            <x-admin.form-section :title="__('ملاحظات')">
                <x-admin.field :label="__('ملاحظات (اختياري)')" name="notes">
                    <textarea name="notes" rows="2"
                              class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes') }}</textarea>
                </x-admin.field>
            </x-admin.form-section>

            <x-admin.form-section :title="__('ملخّص المبيعة')">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('عدد الأصناف') }}</dt>
                        <dd class="font-medium text-gray-900 tabular-nums" x-text="rows.length"></dd>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                        <dt class="font-semibold text-gray-900">{{ __('الإجمالي') }}</dt>
                        <dd class="text-lg font-bold text-emerald-600 tabular-nums"><span x-text="subtotal.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                </dl>
                <div class="flex flex-col gap-2 pt-2">
                    <button type="submit" class="btn-primary w-full justify-center">{{ __('إتمام البيع وخصم المخزون') }}</button>
                    <a href="{{ route('admin.sales.orders.index') }}" class="btn-secondary w-full justify-center">{{ __('إلغاء') }}</a>
                </div>
                <p class="text-xs text-gray-400 text-center">{{ __('لا يُرسَل لشركة التوصيل — تسليم مباشر من المستودع.') }}</p>
            </x-admin.form-section>
        </div>
    </form>

    @push('scripts')
        <script>
            function directSale(products, customers) {
                return {
                    products: products || [],
                    customers: customers || [],
                    query: '', showResults: false, rows: [],
                    // حالة العميل
                    custMode: 'new',
                    customerName: @js(old('customer_name') ?? ''),
                    customerPhone: @js(old('customer_phone') ?? ''),
                    custQuery: '', showCustResults: false, selectedCustomer: null,
                    get filteredCustomers() {
                        const q = this.custQuery.trim().toLowerCase();
                        const list = q === '' ? this.customers
                            : this.customers.filter(c => (c.name || '').toLowerCase().includes(q) || (c.phone || '').includes(q));
                        return list.slice(0, 30);
                    },
                    pickCustomer(c) { this.selectedCustomer = c; this.showCustResults = false; this.custQuery = ''; },
                    clearCustomer() { this.selectedCustomer = null; this.custQuery = ''; },
                    get filteredProducts() {
                        const q = this.query.trim().toLowerCase();
                        const list = q === '' ? this.products
                            : this.products.filter(p => (p.name || '').toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q));
                        return list.slice(0, 30);
                    },
                    get subtotal() { return this.rows.reduce((s, r) => s + this.lineTotal(r), 0); },
                    lineTotal(r) { return (Number(r.qty) || 0) * (Number(r.unit_price) || 0); },
                    addProduct(p) {
                        const existing = this.rows.find(r => r.variant === p.variant);
                        if (existing) { existing.qty = (Number(existing.qty) || 0) + 1; }
                        else { this.rows.push({ variant: p.variant, name: p.name, sku: p.sku, image: p.image, unit_price: p.price, qty: 1 }); }
                        this.query = ''; this.showResults = false;
                    },
                    removeRow(i) { this.rows.splice(i, 1); },
                };
            }
        </script>
    @endpush
</x-app-layout>
