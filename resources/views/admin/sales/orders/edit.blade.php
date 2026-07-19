<x-app-layout :title="__('تعديل الطلب')">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ __('تعديل الطلب') }} {{ $order->number }}</h2>
    </x-slot>

    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $existingRows = $order->items->filter(fn ($it) => $it->variant)->map(fn ($it) => [
            'variant' => $it->variant->uuid,
            'name' => $it->variant->product?->name ?? $it->variant->name ?? $it->variant->sku ?? __('صنف'),
            'sku' => $it->variant->sku,
            'image' => null,
            'unit_price' => (float) $it->unit_price,
            'qty' => (float) $it->qty,
            'discount' => (float) $it->discount,
        ])->values();
    @endphp

    <div class="py-8 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6"
             x-data="editOrderForm(@js($products), @js($existingRows), @js((float) $order->shipping_total))">
            <x-admin.flash />

            <p class="text-sm text-gray-500 mb-4">
                {{ __('تصحيح بيانات الطلب. عند تعديل الأصناف/الكميات/الأسعار يُحدَّث القيد المحاسبي الموجود وحركة المخزون تلقائيًا (لا قيد جديد).') }}
            </p>

            <form method="POST" action="{{ route('admin.sales.orders.update', $order) }}" class="space-y-6"
                  @submit="if (rows.length === 0) { $event.preventDefault(); alert('{{ __('أضف صنفًا واحدًا على الأقل.') }}'); }">
                @csrf
                @method('PUT')

                {{-- ————— بيانات التواصل/التوصيل ————— --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('اسم العميل') }} <span class="text-rose-500">*</span></label>
                        <input type="text" name="customer_name" required value="{{ old('customer_name', $order->customer_name) }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        @error('customer_name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('رقم الهاتف') }} <span class="text-rose-500">*</span></label>
                        <input type="text" name="customer_phone" required inputmode="numeric" dir="ltr"
                               value="{{ old('customer_phone', $order->customer_phone) }}" placeholder="0599123456"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 text-right" />
                        @error('customer_phone')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('البريد الإلكتروني') }}</label>
                        <input type="email" name="customer_email" dir="ltr" value="{{ old('customer_email', $order->customer_email) }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 text-right" />
                        @error('customer_email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('العنوان') }} <span class="text-rose-500">*</span></label>
                        <input type="text" name="shipping_address" required value="{{ old('shipping_address', $order->shipping_address) }}"
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        @error('shipping_address')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- ————— الأصناف ————— --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الأصناف') }}</label>
                    <div class="relative" @click.outside="showResults = false">
                        <div class="relative">
                            <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.2-5.2m2.2-5.3a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/></svg>
                            </span>
                            <input type="text" x-model="query" @focus="showResults = true"
                                   placeholder="{{ __('ابحث عن صنف لإضافته…') }}"
                                   class="w-full rounded-lg border-gray-300 ps-10 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        </div>
                        <div x-show="showResults && filteredProducts.length" x-transition x-cloak
                             class="absolute z-20 mt-1 w-full max-h-72 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg">
                            <template x-for="p in filteredProducts" :key="p.variant">
                                <button type="button" @click="addProduct(p)"
                                        class="w-full flex items-center gap-3 px-3 py-2 hover:bg-emerald-50 text-start">
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-gray-800 truncate" x-text="p.name"></span>
                                        <span class="block text-xs text-gray-400" x-text="p.sku"></span>
                                    </span>
                                    <span class="text-sm font-semibold text-emerald-600 tabular-nums" x-text="p.price.toFixed(2) + ' {{ $sym }}'"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <template x-if="rows.length === 0">
                        <div class="mt-2 text-center py-6 text-sm text-gray-400 border border-dashed border-gray-200 rounded-lg">
                            {{ __('لا أصناف — ابحث أعلاه وأضف.') }}
                        </div>
                    </template>

                    <div class="mt-2 divide-y divide-gray-100" x-show="rows.length">
                        <template x-for="(row, i) in rows" :key="row.variant">
                            <div class="flex flex-wrap items-end gap-3 py-3">
                                <div class="min-w-0 flex-1 basis-full sm:basis-auto">
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
                                <div>
                                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ __('الخصم') }}</label>
                                    <input type="number" step="0.01" min="0" x-model.number="row.discount"
                                           class="w-20 rounded-md border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                                </div>
                                <div class="w-20 text-start">
                                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ __('الإجمالي') }}</label>
                                    <span class="text-sm font-semibold text-gray-900 tabular-nums" x-text="lineTotal(row).toFixed(2)"></span>
                                </div>
                                <button type="button" @click="removeRow(i)" class="text-rose-400 hover:text-rose-600 shrink-0 mb-1">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>

                                <input type="hidden" :name="`items[${i}][variant]`" :value="row.variant" />
                                <input type="hidden" :name="`items[${i}][qty]`" :value="row.qty" />
                                <input type="hidden" :name="`items[${i}][unit_price]`" :value="row.unit_price" />
                                <input type="hidden" :name="`items[${i}][discount]`" :value="row.discount || 0" />
                            </div>
                        </template>
                    </div>
                    @error('items')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                {{-- ————— ملخّص ————— --}}
                <dl class="space-y-2 text-sm border-t pt-4">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('الإجمالي الفرعي') }}</dt>
                        <dd class="font-medium text-gray-900 tabular-nums"><span x-text="subtotal.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('رسوم التوصيل') }}</dt>
                        <dd class="font-medium text-gray-900 tabular-nums"><span x-text="shipping.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                    <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                        <dt class="font-semibold text-gray-900">{{ __('الإجمالي') }}</dt>
                        <dd class="text-lg font-bold text-emerald-600 tabular-nums"><span x-text="total.toFixed(2)"></span> {{ $sym }}</dd>
                    </div>
                </dl>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ملاحظات') }}</label>
                    <textarea name="notes" rows="2"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes', $order->notes) }}</textarea>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2 border-t">
                    <a href="{{ route('admin.sales.orders.show', $order) }}"
                       class="text-center px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">{{ __('إلغاء') }}</a>
                    <button type="submit"
                            class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('حفظ التعديلات') }}</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            function editOrderForm(products, initialRows, shippingTotal) {
                return {
                    products: products || [],
                    rows: (initialRows || []).map(r => ({ ...r })),
                    query: '',
                    showResults: false,
                    shipping: Number(shippingTotal) || 0,

                    get filteredProducts() {
                        const q = this.query.trim().toLowerCase();
                        const list = q === ''
                            ? this.products
                            : this.products.filter(p =>
                                (p.name || '').toLowerCase().includes(q) ||
                                (p.sku || '').toLowerCase().includes(q));
                        return list.slice(0, 30);
                    },
                    lineTotal(r) {
                        return Math.max(0, (Number(r.qty) || 0) * (Number(r.unit_price) || 0) - (Number(r.discount) || 0));
                    },
                    get subtotal() {
                        return this.rows.reduce((s, r) => s + (Number(r.qty) || 0) * (Number(r.unit_price) || 0), 0);
                    },
                    get discountTotal() {
                        return this.rows.reduce((s, r) => s + (Number(r.discount) || 0), 0);
                    },
                    get total() {
                        return Math.max(0, this.subtotal - this.discountTotal) + this.shipping;
                    },
                    addProduct(p) {
                        const existing = this.rows.find(r => r.variant === p.variant);
                        if (existing) {
                            existing.qty = (Number(existing.qty) || 0) + 1;
                        } else {
                            this.rows.push({
                                variant: p.variant, name: p.name, sku: p.sku,
                                image: p.image, unit_price: p.price, qty: 1, discount: 0,
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
