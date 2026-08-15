<x-app-layout :title="$editing ? __('تعديل فاتورة شراء') : __('فاتورة شراء جديدة')">
    <x-admin.header
        :title="$editing ? __('تعديل فاتورة شراء') : __('فاتورة شراء جديدة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('فواتير الشراء') => route('admin.purchasing.invoices.index'), ($editing ? __('تعديل') : __('جديدة')) => null]" />

    <x-admin.flash />

    @php
        // ثلاث عملات تجتمع في هذه الشاشة، فرمزُ كل رقم جزءٌ من معناه.
        $baseSymbol = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $head = [
            'currency' => old('currency', $editing ? $invoice->currency : $baseCurrency),
            'fx_rate_to_usd' => (float) old('fx_rate_to_usd', $editing ? (float) $invoice->fx_rate_to_usd : 0),
            'usd_rate' => (float) old('usd_rate', $editing ? (float) $invoice->usd_rate : 0),
            'commission_rate' => (float) old('commission_rate', $editing ? (float) $invoice->commission_rate : 0),
            'cbm_rate_usd' => (float) old('cbm_rate_usd', $editing ? (float) $invoice->cbm_rate_usd : 0),
            'kind' => old('kind', $editing ? $invoice->kind : 'goods'),
            'import_shipment_id' => (string) old('import_shipment_id', $editing ? $invoice->import_shipment_id : ''),
        ];
    @endphp

    <form method="POST" action="{{ $editing ? route('admin.purchasing.invoices.update', $invoice) : route('admin.purchasing.invoices.store') }}"
          x-data="invoiceForm(@js($initialRows), @js($head), @js($variantCbm), @js($baseCurrency), @js($currencies))" class="space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-admin.form-section :title="__('بيانات الفاتورة')" :cols="2">
            <x-admin.field :label="__('المورد')" name="supplier_id" required>
                <select name="supplier_id" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— اختر —') }}</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(old('supplier_id', $editing ? $invoice->supplier_id : null) == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('رقم فاتورة المورد')" name="supplier_reference">
                <input type="text" name="supplier_reference" value="{{ old('supplier_reference', $editing ? $invoice->supplier_reference : '') }}" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
            <x-admin.field :label="__('تاريخ الفاتورة')" name="invoice_date" required>
                <input type="date" name="invoice_date" value="{{ old('invoice_date', $editing ? $invoice->invoice_date?->toDateString() : now()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
            <x-admin.field :label="__('تاريخ الاستحقاق')" name="due_date">
                <input type="date" name="due_date" value="{{ old('due_date', $editing ? $invoice->due_date?->toDateString() : '') }}" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>
        </x-admin.form-section>

        {{--
            الشحنة ونوع الفاتورة: فاتورة البضاعة تُحمّل الحساب الوسيط بتقديرها،
            وفاتورة المصاريف — التي تصل بعدها بأشهر — تُطفئه بالفعلي. ربطُهما
            بالشحنة نفسها هو ما يجعل الرصيد قابلًا للإغلاق لاحقًا.
        --}}
        <x-admin.form-section :title="__('الشحنة ونوع الفاتورة')" :cols="2">
            <x-admin.field :label="__('نوع الفاتورة')" name="kind">
                <select name="kind" x-model="head.kind" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="goods">{{ __('بضاعة — تدخل المخزون') }}</option>
                    <option value="expenses">{{ __('مصاريف شحنة — شحن بحري/جمارك/عمولة مكتب') }}</option>
                </select>
                <p class="mt-1 text-xs text-gray-500" x-show="isExpense()" x-cloak>
                    {{ __('تُقيَّد على «مصاريف استيراد مستحقة» ولا تُدخل بضاعة. البنود وصفٌ ومبلغ.') }}
                </p>
            </x-admin.field>

            <x-admin.field :label="__('الشحنة / الكونتينر')" name="import_shipment_id">
                <select name="import_shipment_id" x-model="head.import_shipment_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— بلا شحنة —') }}</option>
                    @foreach ($shipments as $sh)
                        <option value="{{ $sh->id }}">{{ $sh->number }}{{ $sh->reference ? ' — '.$sh->reference : '' }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-rose-600" x-show="isExpense() && !head.import_shipment_id" x-cloak>
                    {{ __('فاتورة المصاريف تحتاج شحنة — بغيرها لا يُعرف أيّ تقدير تُطفئ.') }}
                </p>
            </x-admin.field>
        </x-admin.form-section>

        {{--
            العملة وسعرا الصرف. تُترك فارغة في الفاتورة المحلية فتبقى الحسابات
            كما كانت تمامًا (الحاسبة معطّلة).
        --}}
        <x-admin.form-section :title="__('العملة والاستيراد')" :cols="2">
            <x-admin.field :label="__('عملة فاتورة المورد')" name="currency">
                <select name="currency" x-model="head.currency" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    @foreach ($currencies as $code => $sym)
                        <option value="{{ $code }}">{{ $code }} ({{ $sym }})</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500" x-show="head.currency === base" x-cloak>
                    {{ __('فاتورة محلية — تُكتب التكلفة بالعملة الأساسية مباشرة.') }}
                </p>
            </x-admin.field>

            <div x-show="head.currency !== base" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.field :label="__('سعر الصرف مقابل الدولار')" name="fx_rate_to_usd">
                    <input type="number" step="0.000001" min="0" name="fx_rate_to_usd" x-model.number="head.fx_rate_to_usd"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('كم') }} <span class="font-medium" x-text="symbol(head.currency)"></span> {{ __('يساوي 1 $ — مثال: 7.15') }}
                    </p>
                </x-admin.field>
                <x-admin.field :label="__('سعر الدولار بالعملة الأساسية')" name="usd_rate">
                    <input type="number" step="0.000001" min="0" name="usd_rate" x-model.number="head.usd_rate"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('كم :c يساوي 1 $ — مثال: 3.65', ['c' => $currencies[$baseCurrency] ?? $baseCurrency]) }}</p>
                </x-admin.field>
                {{-- العمولة والشحن يُحمَّلان على البضاعة لا على فاتورة الشحن نفسها --}}
                <template x-if="!isExpense()">
                    <x-admin.field :label="__('عمولة المشتريات %')" name="commission_rate">
                        <input type="number" step="0.001" min="0" max="100" name="commission_rate" x-model.number="head.commission_rate"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                </template>
                <template x-if="!isExpense()">
                    <x-admin.field :label="__('تكلفة المتر المكعّب (CBM) بالدولار')" name="cbm_rate_usd">
                        <input type="number" step="0.0001" min="0" name="cbm_rate_usd" x-model.number="head.cbm_rate_usd"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('يُوزَّع الشحن البحري على الأصناف بحسب حجم كل صنف.') }}</p>
                    </x-admin.field>
                </template>
            </div>
        </x-admin.form-section>

        <x-admin.form-section :title="__('البنود')">
            {{--
                جدول الأرقام: عمود الصنف مقيَّد بعرض ثابت حتى لا يبتلع المساحة،
                وأعمدة الأرقام أوسع لأن الأرقام هي ما يُقرأ ويُدقَّق هنا. الحشو
                مُخفَّض عن الجداول الأخرى (px-2) لصالح عرض الحقول نفسها، والجدول
                `table-fixed` فلا يتغيّر ترتيب الأعمدة بطول محتواها.
            --}}
            <div class="overflow-x-auto -mx-1">
                <table class="admin-table admin-table--dense table-fixed w-full min-w-[1080px]">
                    <thead>
                        <tr>
                            <th class="w-[240px]">{{ __('الصنف / الوصف') }}</th>
                            <th class="w-[110px] text-center">{{ __('الكمية') }}</th>
                            <th class="w-[126px] text-center" x-show="isImport()" x-cloak>
                                {{ __('السعر') }} <span x-text="symbol(head.currency)"></span>
                            </th>
                            <th class="w-[116px] text-center" x-show="isImport() && !isExpense()" x-cloak>{{ __('CBM/وحدة') }}</th>
                            <th class="w-[136px] text-center">
                                <span x-show="!isImport()">{{ __('تكلفة الوحدة') }}</span>
                                <span x-show="isImport()" x-cloak>{{ __('السعر الحقيقي') }}</span>
                            </th>
                            <th class="w-[148px] text-center" x-show="isImport() && !isExpense()" x-cloak>{{ __('التكلفة الشاملة') }}</th>
                            <th class="w-[92px] text-center">{{ __('ضريبة %') }}</th>
                            <th class="w-[130px] text-center">{{ __('الإجمالي') }}</th>
                            <th class="w-[44px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr>
                                <td class="align-top">
                                    <label class="flex items-start gap-1.5 text-[11px] leading-tight text-emerald-700 mb-1.5 cursor-pointer">
                                        <input type="checkbox" x-model="row.is_new" @change="row.is_new ? (row.variant_id = '') : (row.new_name = '', row.sell_price = 0)" class="mt-px rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 shrink-0" />
                                        {{ __('منتج جديد — سجّله في المخزن') }}
                                    </label>

                                    {{-- صنف موجود --}}
                                    <template x-if="!row.is_new">
                                        <div>
                                            <select :name="`items[${i}][variant_id]`" x-model="row.variant_id" @change="fillCbm(row)" class="w-full rounded-md border-gray-300 py-2 text-sm truncate focus:border-emerald-500 focus:ring-emerald-500">
                                                <option value="">{{ __('— صنف حرّ (وصف) —') }}</option>
                                                @foreach ($variants as $v)
                                                    {{-- المقاس/اللون في الاسم: بلا ذلك تتشابه مقاسات المنتج الواحد --}}
                                                    <option value="{{ $v->id }}">{{ $v->product?->name }}@if ($v->attributeValues->isNotEmpty()) — {{ $v->optionLabel() }}@endif</option>
                                                @endforeach
                                            </select>
                                            <input type="text" :name="`items[${i}][description]`" x-model="row.description" placeholder="{{ __('وصف (اختياري)') }}" class="mt-1 w-full rounded-md border-gray-200 py-1.5 text-xs focus:border-emerald-500 focus:ring-emerald-500" />
                                        </div>
                                    </template>

                                    {{-- صنف جديد يُعرَّف من الفاتورة --}}
                                    <template x-if="row.is_new">
                                        <div class="space-y-1">
                                            <input type="text" :name="`items[${i}][new_name]`" x-model="row.new_name" placeholder="{{ __('اسم المنتج الجديد') }}" class="w-full rounded-md border-emerald-300 bg-emerald-50/40 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                            <input type="number" step="0.01" min="0" :name="`items[${i}][sell_price]`" x-model.number="row.sell_price" placeholder="{{ __('سعر البيع') }}" class="w-full rounded-md border-emerald-300 bg-emerald-50/40 py-1.5 text-xs tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500" />
                                            <span class="block text-[11px] leading-tight text-gray-500">{{ __('تُنشأ بطاقة صنف تلقائيًا') }}</span>
                                        </div>
                                    </template>
                                </td>

                                <td class="align-top">
                                    <input type="number" step="0.001" min="0.001" :name="`items[${i}][qty]`" x-model.number="row.qty"
                                           class="w-full rounded-md border-gray-300 px-2 py-2 text-[15px] tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500" />
                                </td>

                                {{-- سعر الوحدة بعملة المورد وحجمها — مدخلات الاستيراد --}}
                                <td class="align-top" x-show="isImport()" x-cloak>
                                    <input type="number" step="0.0001" min="0" :name="`items[${i}][unit_price_foreign]`" x-model.number="row.unit_price_foreign"
                                           class="w-full rounded-md border-gray-300 px-2 py-2 text-[15px] tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500" />
                                </td>
                                <td class="align-top" x-show="isImport() && !isExpense()" x-cloak>
                                    <input type="number" step="any" min="0" :name="`items[${i}][cbm_per_unit]`" x-model.number="row.cbm_per_unit"
                                           class="w-full rounded-md border-gray-300 px-2 py-2 text-[15px] tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500" />
                                </td>

                                {{--
                                    السعر الحقيقي: يُكتب يدويًا محليًا، ويُشتقّ من الصرف في
                                    الاستيراد. يبقى الحقل مُرسَلًا في الحالتين — والخلفية
                                    تُعيد حسابه فلا يُعتمد على قيمة الواجهة.
                                --}}
                                <td class="align-top">
                                    <input type="number" step="0.0001" min="0" :name="`items[${i}][unit_cost]`"
                                           :value="isImport() ? unitCostBase(row).toFixed(4) : row.unit_cost"
                                           @input="row.unit_cost = $event.target.value" :readonly="isImport()"
                                           class="w-full rounded-md border-gray-300 px-2 py-2 text-[15px] tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500 read-only:bg-gray-50 read-only:text-gray-500" />
                                </td>

                                {{-- التكلفة الشاملة: تحسبها الآلة، وتعديلها يدويًا يُثبّتها --}}
                                <td class="align-top" x-show="isImport() && !isExpense()" x-cloak>
                                    <input type="number" step="0.0001" min="0" :name="`items[${i}][landed_unit_cost]`"
                                           :value="row.landed_is_manual ? row.landed_unit_cost : landedUnitCost(row).toFixed(4)"
                                           @input="row.landed_is_manual = true; row.landed_unit_cost = $event.target.value"
                                           class="w-full rounded-md px-2 py-2 text-[15px] font-medium tabular-nums text-center focus:ring-emerald-500"
                                           :class="row.landed_is_manual ? 'border-amber-400 bg-amber-50/60 focus:border-amber-500' : 'border-emerald-300 bg-emerald-50/40 focus:border-emerald-500'" />
                                    <input type="hidden" :name="`items[${i}][landed_is_manual]`" :value="row.landed_is_manual ? 1 : 0" />
                                    <button type="button" x-show="row.landed_is_manual" @click="row.landed_is_manual = false"
                                            class="mt-1 block w-full text-center text-[11px] leading-tight text-amber-700 hover:underline">{{ __('يدوي — عُد للحساب الآلي') }}</button>
                                </td>

                                <td class="align-top">
                                    <input type="number" step="0.01" min="0" max="100" :name="`items[${i}][tax_rate]`" x-model.number="row.tax_rate"
                                           class="w-full rounded-md border-gray-300 px-2 py-2 text-[15px] tabular-nums text-center focus:border-emerald-500 focus:ring-emerald-500" />
                                </td>

                                {{-- الإجمالي محسوب لا مُدخَل: يُعرض بخلفية هادئة ليُقرأ لا ليُنقر --}}
                                <td class="align-top">
                                    <div class="rounded-md bg-gray-50 border border-gray-200 px-2 py-2 text-[15px] font-semibold tabular-nums text-center text-gray-800"
                                         x-text="lineTotal(row).toFixed(2)"></div>
                                </td>

                                <td class="align-top text-center">
                                    <button type="button" @click="rows.splice(i,1)" x-show="rows.length > 1"
                                            :title="'{{ __('حذف البند') }}'"
                                            class="mt-1.5 grid place-items-center w-7 h-7 mx-auto rounded-md text-rose-500 hover:bg-rose-50 hover:text-rose-700">&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between mt-3">
                <button type="button" @click="addRow()" class="btn-secondary btn-sm">+ {{ __('إضافة بند') }}</button>
                <div class="text-sm text-gray-600 space-y-0.5 text-start">
                    <div>{{ __('الإجمالي الفرعي') }}: <span class="font-medium tabular-nums" x-text="subtotal().toFixed(2)"></span> <span class="text-xs text-gray-400">{{ $baseSymbol }}</span></div>
                    <div>{{ __('الضريبة') }}: <span class="font-medium tabular-nums" x-text="tax().toFixed(2)"></span> <span class="text-xs text-gray-400">{{ $baseSymbol }}</span></div>
                    <div class="text-base font-bold text-gray-900">{{ __('الإجمالي') }}: <span class="tabular-nums" x-text="total().toFixed(2)"></span> {{ \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪') }}</div>
                </div>
            </div>

            {{-- لوحة الاستيراد: الذمّة بالعملات الثلاث، وقيمة المخزون، والفرق بينهما --}}
            <div x-show="isImport() && !isExpense()" x-cloak class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/40 p-4">
                <h4 class="text-sm font-semibold text-emerald-900 mb-3">{{ __('ملخّص الاستيراد') }}</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('ذمّة المورد') }} <span x-text="symbol(head.currency)"></span></p>
                        <p class="font-semibold tabular-nums text-gray-800"><span x-text="foreignSubtotal().toFixed(2)"></span><span class="ms-1 text-xs font-normal text-gray-400" x-text="symbol(head.currency)"></span></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('ذمّة المورد') }} $</p>
                        <p class="font-semibold tabular-nums text-gray-800"><span x-text="toUsd(subtotal()).toFixed(2)"></span><span class="ms-1 text-xs font-normal text-gray-400">$</span></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('ذمّة المورد') }} {{ $currencies[$baseCurrency] ?? $baseCurrency }}</p>
                        <p class="font-semibold tabular-nums text-gray-800"><span x-text="subtotal().toFixed(2)"></span><span class="ms-1 text-xs font-normal text-gray-400">{{ $baseSymbol }}</span></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('إجمالي الحجم') }} CBM</p>
                        <p class="font-semibold tabular-nums text-gray-800" x-text="totalCbm().toFixed(4)"></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mt-3 pt-3 border-t border-emerald-200">
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('قيمة المخزون (شاملة)') }}</p>
                        <p class="font-bold tabular-nums text-emerald-800"><span x-text="landedSubtotal().toFixed(2)"></span><span class="ms-1 text-xs font-normal text-gray-400">{{ $baseSymbol }}</span></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('مصاريف استيراد مستحقة') }}</p>
                        <p class="font-bold tabular-nums text-amber-700"><span x-text="importDifference().toFixed(2)"></span><span class="ms-1 text-xs font-normal text-gray-400">{{ $baseSymbol }}</span></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('شحن بحري مقدَّر') }} $</p>
                        <p class="tabular-nums text-gray-700" x-text="(totalCbm() * (Number(head.cbm_rate_usd) || 0)).toFixed(2)"></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs">{{ __('عمولة مشتريات مقدَّرة') }} $</p>
                        <p class="tabular-nums text-gray-700" x-text="(toUsd(subtotal()) * (Number(head.commission_rate) || 0) / 100).toFixed(2)"></p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    {{ __('عند الحفظ: يُدان المخزون بالتكلفة الشاملة، وتُدان ذمّة المورد بسعرها الحقيقي، ويُقيَّد الفرق في «مصاريف استيراد مستحقة» حتى تصل فاتورة الشحن والمصاريف.') }}
                </p>
            </div>
            @error('items')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </x-admin.form-section>

        <x-admin.field :label="__('ملاحظات')" name="notes">
            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes', $editing ? $invoice->notes : '') }}</textarea>
        </x-admin.field>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ $editing ? route('admin.purchasing.invoices.show', $invoice) : route('admin.purchasing.invoices.index') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
            <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
        </div>
    </form>

    @push('scripts')
        <script>
            /**
             * معادلة التكلفة هنا مرآةٌ لـ ImportCostCalculator في الخلفية — للعرض الفوري
             * فقط. الخلفية تُعيد الحساب عند الحفظ، فاختلافُ أي رقم هنا لا يُفسد البيانات.
             */
            const EMPTY_ROW = {
                is_new: false, variant_id: '', new_name: '', sell_price: 0, description: '',
                qty: 1, unit_cost: 0, tax_rate: 0,
                unit_price_foreign: 0, cbm_per_unit: 0, landed_unit_cost: 0, landed_is_manual: false,
            };

            function invoiceForm(initial, head, variantCbm, base, currencies) {
                return {
                    rows: (initial && initial.length) ? initial : [{ ...EMPTY_ROW }],
                    head,
                    base,
                    currencies,
                    variantCbm: variantCbm || {},

                    addRow() { this.rows.push({ ...EMPTY_ROW }); },
                    symbol(code) { return this.currencies[code] || code; },

                    /** فاتورة مصاريف شحنة: تحويلُ عملة فقط، بلا عمولة ولا شحن. */
                    isExpense() { return this.head.kind === 'expenses'; },

                    /** الحاسبة عاملة بعملة أجنبية وسعري صرف موجبين — وإلا فاتورة محلية. */
                    isImport() {
                        return this.head.currency !== this.base
                            && Number(this.head.fx_rate_to_usd) > 0
                            && Number(this.head.usd_rate) > 0;
                    },

                    /** حجم الصنف من كرت الصنف عند اختياره — ولا يدهس ما كُتب يدويًا. */
                    fillCbm(r) {
                        if (Number(r.cbm_per_unit) > 0) return;
                        r.cbm_per_unit = this.variantCbm[String(r.variant_id)] || 0;
                    },

                    unitPriceUsd(r) {
                        const fx = Number(this.head.fx_rate_to_usd) || 0;
                        return fx > 0 ? (Number(r.unit_price_foreign) || 0) / fx : 0;
                    },
                    unitCostBase(r) { return this.unitPriceUsd(r) * (Number(this.head.usd_rate) || 0); },
                    landedUnitCost(r) {
                        const usd = this.unitPriceUsd(r);
                        if (this.isExpense()) return usd * (Number(this.head.usd_rate) || 0);
                        const commission = usd * (Number(this.head.commission_rate) || 0) / 100;
                        const freight = Math.max(Number(r.cbm_per_unit) || 0, 0) * (Number(this.head.cbm_rate_usd) || 0);
                        return (usd + commission + freight) * (Number(this.head.usd_rate) || 0);
                    },

                    /** السعر الحقيقي المعتمَد للسطر: مُشتقّ في الاستيراد، مكتوبٌ محليًا. */
                    effectiveCost(r) { return this.isImport() ? this.unitCostBase(r) : (Number(r.unit_cost) || 0); },
                    effectiveLanded(r) {
                        if (!this.isImport()) return this.effectiveCost(r);
                        return r.landed_is_manual ? (Number(r.landed_unit_cost) || 0) : this.landedUnitCost(r);
                    },

                    lineTotal(r) { return (Number(r.qty) || 0) * this.effectiveCost(r); },
                    lineTax(r) { return this.lineTotal(r) * (Number(r.tax_rate) || 0) / 100; },
                    subtotal() { return this.rows.reduce((s, r) => s + this.lineTotal(r), 0); },
                    tax() { return this.rows.reduce((s, r) => s + this.lineTax(r), 0); },
                    total() { return this.subtotal() + this.tax(); },

                    foreignSubtotal() { return this.rows.reduce((s, r) => s + (Number(r.qty) || 0) * (Number(r.unit_price_foreign) || 0), 0); },
                    landedSubtotal() { return this.rows.reduce((s, r) => s + (Number(r.qty) || 0) * this.effectiveLanded(r), 0); },
                    totalCbm() { return this.rows.reduce((s, r) => s + (Number(r.qty) || 0) * (Number(r.cbm_per_unit) || 0), 0); },
                    importDifference() { return this.landedSubtotal() - this.subtotal(); },
                    toUsd(amount) {
                        const rate = Number(this.head.usd_rate) || 0;
                        return rate > 0 ? amount / rate : 0;
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
