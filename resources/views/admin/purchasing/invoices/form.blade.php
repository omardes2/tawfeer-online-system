<x-app-layout :title="__('فاتورة شراء جديدة')">
    <x-admin.header
        :title="__('فاتورة شراء جديدة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('فواتير الشراء') => route('admin.purchasing.invoices.index'), __('جديدة') => null]" />

    <x-admin.flash />

    <form method="POST" action="{{ route('admin.purchasing.invoices.store') }}"
          x-data="invoiceForm()" class="space-y-6">
        @csrf

        <x-admin.form-section :title="__('بيانات الفاتورة')" :cols="2">
            <x-admin.field :label="__('المورد')" name="supplier_id" required>
                <select name="supplier_id" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— اختر —') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('رقم فاتورة المورد')" name="supplier_reference">
                <input type="text" name="supplier_reference" value="{{ old('supplier_reference') }}" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
            <x-admin.field :label="__('تاريخ الفاتورة')" name="invoice_date" required>
                <input type="date" name="invoice_date" value="{{ old('invoice_date', now()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
            <x-admin.field :label="__('تاريخ الاستحقاق')" name="due_date">
                <input type="date" name="due_date" value="{{ old('due_date') }}" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
        </x-admin.form-section>

        <x-admin.form-section :title="__('البنود')">
            <div class="overflow-x-auto -mx-1">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>{{ __('الصنف / الوصف') }}</th>
                            <th class="w-24">{{ __('الكمية') }}</th>
                            <th class="w-28">{{ __('تكلفة الوحدة') }}</th>
                            <th class="w-20">{{ __('ضريبة %') }}</th>
                            <th class="w-28 text-start">{{ __('الإجمالي') }}</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr>
                                <td>
                                    <select :name="`items[${i}][variant_id]`" x-model="row.variant_id" class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <option value="">{{ __('— صنف حرّ (وصف) —') }}</option>
                                        @foreach ($variants as $v)
                                            <option value="{{ $v->id }}">{{ $v->product?->name }} — {{ $v->sku }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" :name="`items[${i}][description]`" x-model="row.description" placeholder="{{ __('وصف (اختياري)') }}" class="mt-1 w-full rounded-md border-gray-200 text-xs focus:border-emerald-500 focus:ring-emerald-500" />
                                </td>
                                <td><input type="number" step="0.001" min="0.001" :name="`items[${i}][qty]`" x-model.number="row.qty" class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" /></td>
                                <td><input type="number" step="0.01" min="0" :name="`items[${i}][unit_cost]`" x-model.number="row.unit_cost" class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" /></td>
                                <td><input type="number" step="0.01" min="0" max="100" :name="`items[${i}][tax_rate]`" x-model.number="row.tax_rate" class="w-full rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" /></td>
                                <td class="text-start tabular-nums text-sm" x-text="lineTotal(row).toFixed(2)"></td>
                                <td><button type="button" @click="rows.splice(i,1)" x-show="rows.length > 1" class="text-rose-500 hover:text-rose-700">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between mt-3">
                <button type="button" @click="rows.push({variant_id:'',description:'',qty:1,unit_cost:0,tax_rate:0})" class="btn-secondary btn-sm">+ {{ __('إضافة بند') }}</button>
                <div class="text-sm text-gray-600 space-y-0.5 text-start">
                    <div>{{ __('الإجمالي الفرعي') }}: <span class="font-medium tabular-nums" x-text="subtotal().toFixed(2)"></span></div>
                    <div>{{ __('الضريبة') }}: <span class="font-medium tabular-nums" x-text="tax().toFixed(2)"></span></div>
                    <div class="text-base font-bold text-gray-900">{{ __('الإجمالي') }}: <span class="tabular-nums" x-text="total().toFixed(2)"></span> {{ \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪') }}</div>
                </div>
            </div>
            @error('items')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </x-admin.form-section>

        <x-admin.field :label="__('ملاحظات')" name="notes">
            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes') }}</textarea>
        </x-admin.field>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.purchasing.invoices.index') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
            <button type="submit" class="btn-primary">{{ __('حفظ كمسودّة') }}</button>
        </div>
    </form>

    @push('scripts')
        <script>
            function invoiceForm() {
                return {
                    rows: [{ variant_id: '', description: '', qty: 1, unit_cost: 0, tax_rate: 0 }],
                    lineTotal(r) { return (Number(r.qty) || 0) * (Number(r.unit_cost) || 0); },
                    lineTax(r) { return this.lineTotal(r) * (Number(r.tax_rate) || 0) / 100; },
                    subtotal() { return this.rows.reduce((s, r) => s + this.lineTotal(r), 0); },
                    tax() { return this.rows.reduce((s, r) => s + this.lineTax(r), 0); },
                    total() { return this.subtotal() + this.tax(); },
                };
            }
        </script>
    @endpush
</x-app-layout>
