<x-app-layout :title="$invoice->number">
    @php
        $statusTone = ['draft' => 'gray', 'approved' => 'blue', 'posted' => 'green', 'cancelled' => 'red', 'reversed' => 'amber'];
        $statusLabel = ['draft' => 'مسودّة', 'approved' => 'معتمدة', 'posted' => 'مُرحّلة', 'cancelled' => 'ملغاة', 'reversed' => 'معكوسة'];
        $payTone = ['paid' => 'green', 'partial' => 'amber', 'unpaid' => 'red'];
        $payLabel = ['paid' => 'مدفوعة', 'partial' => 'جزئية', 'unpaid' => 'غير مدفوعة'];
    @endphp

    <x-admin.header
        :title="__('فاتورة شراء :n', ['n' => $invoice->number])"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('فواتير الشراء') => route('admin.purchasing.invoices.index'), $invoice->number => null]">
        <div class="flex items-center gap-2 flex-wrap">
            {{-- تعديل متاح للمسودّة والمعتمدة (قبل الترحيل) --}}
            @if (in_array($invoice->status, ['draft', 'approved']))
                @can('purchasing.invoices.create')
                    <a href="{{ route('admin.purchasing.invoices.edit', $invoice) }}" class="btn-secondary btn-sm">{{ __('تعديل') }}</a>
                @endcan
            @endif
            @if ($invoice->status === 'draft')
                @can('purchasing.invoices.approve')
                    <form method="POST" action="{{ route('admin.purchasing.invoices.approve', $invoice) }}">@csrf<button class="btn-primary btn-sm">{{ __('اعتماد') }}</button></form>
                @endcan
                @can('purchasing.invoices.delete')
                    <x-admin.confirm :action="route('admin.purchasing.invoices.destroy', $invoice)" method="DELETE"
                        :title="__('حذف الفاتورة')" :message="__('سيُحذف السجلّ نهائيًا (المسودّة فقط).')" :confirm="__('حذف')" tone="red" :trigger="__('حذف')" />
                @endcan
            @elseif ($invoice->status === 'approved')
                @can('purchasing.invoices.post')
                    <form method="POST" action="{{ route('admin.purchasing.invoices.post', $invoice) }}">@csrf<button class="btn-primary btn-sm">{{ __('ترحيل محاسبي') }}</button></form>
                @endcan
            @elseif ($invoice->status === 'posted' && (float) $invoice->amount_paid == 0.0)
                @can('purchasing.invoices.post')
                    <x-admin.confirm :action="route('admin.purchasing.invoices.reverse', $invoice)" method="POST"
                        :title="__('عكس الفاتورة')" :message="__('سيُنشأ قيد عاكس (لا حذف).')" :confirm="__('عكس')" tone="amber" :trigger="__('عكس')" />
                @endcan
            @endif
        </div>
    </x-admin.header>

    <x-admin.flash />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- التفاصيل + البنود --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="admin-card admin-card-pad">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><p class="text-gray-500">{{ __('المورد') }}</p><p class="font-medium text-gray-800">{{ $invoice->supplier?->name }}</p></div>
                    <div><p class="text-gray-500">{{ __('تاريخ الفاتورة') }}</p><p class="font-medium text-gray-800">{{ $invoice->invoice_date?->format('Y-m-d') }}</p></div>
                    <div><p class="text-gray-500">{{ __('الاستحقاق') }}</p><p class="font-medium text-gray-800">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('مرجع المورد') }}</p><p class="font-medium text-gray-800">{{ $invoice->supplier_reference ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('الحالة') }}</p><x-admin.badge :tone="$statusTone[$invoice->status] ?? 'gray'" :label="__($statusLabel[$invoice->status] ?? $invoice->status)" /></div>
                    <div><p class="text-gray-500">{{ __('الدفع') }}</p><x-admin.badge :tone="$payTone[$invoice->payment_status] ?? 'gray'" :label="__($payLabel[$invoice->payment_status] ?? $invoice->payment_status)" /></div>
                    @if ($invoice->journalEntry)
                        <div><p class="text-gray-500">{{ __('القيد') }}</p><a href="{{ route('admin.accounting.journal.show', $invoice->journalEntry) }}" class="font-mono text-emerald-600 hover:underline">{{ $invoice->journalEntry->number }}</a></div>
                    @endif
                </div>
            </div>

            @if ($invoice->isImport())
                @php $sym = $currencies[$invoice->currency] ?? $invoice->currency; @endphp
                <div class="admin-card admin-card-pad">
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ __('بيانات الاستيراد') }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div><p class="text-gray-500">{{ __('عملة الفاتورة') }}</p><p class="font-medium text-gray-800">{{ $invoice->currency }} ({{ $sym }})</p></div>
                        <div><p class="text-gray-500">{{ __('الصرف مقابل الدولار') }}</p><p class="font-medium tabular-nums text-gray-800">{{ rtrim(rtrim(number_format($invoice->fx_rate_to_usd, 6), '0'), '.') }}</p></div>
                        <div><p class="text-gray-500">{{ __('سعر الدولار') }}</p><p class="font-medium tabular-nums text-gray-800">{{ rtrim(rtrim(number_format($invoice->usd_rate, 6), '0'), '.') }}</p></div>
                        <div><p class="text-gray-500">{{ __('عمولة المشتريات') }}</p><p class="font-medium tabular-nums text-gray-800">{{ rtrim(rtrim(number_format($invoice->commission_rate, 3), '0'), '.') }}%</p></div>
                        <div><p class="text-gray-500">{{ __('تكلفة المتر المكعّب') }}</p><p class="font-medium tabular-nums text-gray-800">{{ number_format($invoice->cbm_rate_usd, 2) }} $</p></div>
                        <div><p class="text-gray-500">{{ __('إجمالي الحجم') }}</p><p class="font-medium tabular-nums text-gray-800">{{ rtrim(rtrim(number_format($invoice->total_cbm, 4), '0'), '.') }} CBM</p></div>
                        <div><p class="text-gray-500">{{ __('ذمّة المورد') }} ({{ $sym }})</p><p class="font-medium tabular-nums text-gray-800">{{ number_format($invoice->foreign_subtotal, 2) }}</p></div>
                        <div><p class="text-gray-500">{{ __('ذمّة المورد') }} ($)</p><p class="font-medium tabular-nums text-gray-800">{{ number_format((float) $invoice->usd_rate > 0 ? (float) $invoice->subtotal / (float) $invoice->usd_rate : 0, 2) }}</p></div>
                    </div>
                </div>
            @endif

            <x-admin.table :title="__('البنود')">
                <thead>
                    <tr>
                        <th>{{ __('الصنف') }}</th>
                        <th>{{ __('الكمية') }}</th>
                        @if ($invoice->isImport())
                            <th>{{ __('السعر') }} {{ $currencies[$invoice->currency] ?? $invoice->currency }}</th>
                            <th>CBM</th>
                        @endif
                        <th>{{ $invoice->isImport() ? __('السعر الحقيقي') : __('التكلفة') }}</th>
                        @if ($invoice->isImport())
                            <th>{{ __('التكلفة الشاملة') }}</th>
                        @endif
                        <th>{{ __('ضريبة') }}</th>
                        <th class="text-start">{{ __('الإجمالي') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $it)
                        <tr>
                            <td class="text-gray-800">{{ $it->variant?->product?->name ?? $it->description ?? $it->variant?->sku ?? '—' }}</td>
                            <td class="tabular-nums">{{ rtrim(rtrim(number_format($it->qty, 3), '0'), '.') }}</td>
                            @if ($invoice->isImport())
                                <td class="tabular-nums">{{ number_format($it->unit_price_foreign, 2) }}</td>
                                <td class="tabular-nums text-gray-500">{{ rtrim(rtrim(number_format($it->cbm_per_unit, 4), '0'), '.') }}</td>
                            @endif
                            <td class="tabular-nums">{{ number_format($it->unit_cost, 2) }}</td>
                            @if ($invoice->isImport())
                                <td class="tabular-nums font-medium text-emerald-700">
                                    {{ number_format($it->landed_unit_cost, 2) }}
                                    @if ($it->landed_is_manual)
                                        <span class="text-[11px] text-amber-600">{{ __('(يدوي)') }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="tabular-nums text-gray-400">{{ number_format($it->tax_amount, 2) }}</td>
                            <td class="text-start font-medium tabular-nums">{{ number_format($it->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin.table>
        </div>

        {{-- الملخّص المالي + الدفع --}}
        <div class="space-y-6">
            <div class="admin-card admin-card-pad space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">{{ __('الإجمالي الفرعي') }}</span><span class="tabular-nums">{{ number_format($invoice->subtotal, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('الضريبة') }}</span><span class="tabular-nums">{{ number_format($invoice->tax_amount, 2) }}</span></div>
                <div class="flex justify-between text-base font-bold text-gray-900 border-t border-gray-100 pt-2"><span>{{ __('الإجمالي') }}</span><span class="tabular-nums">{{ number_format($invoice->total, 2) }}</span></div>
                <div class="flex justify-between text-emerald-600"><span>{{ __('المدفوع') }}</span><span class="tabular-nums">{{ number_format($invoice->amount_paid, 2) }}</span></div>
                <div class="flex justify-between font-semibold {{ $invoice->balanceDue() > 0 ? 'text-rose-600' : 'text-gray-400' }}"><span>{{ __('المتبقّي') }}</span><span class="tabular-nums">{{ number_format($invoice->balanceDue(), 2) }}</span></div>
            </div>

            @if ($invoice->isImport())
                <div class="admin-card admin-card-pad space-y-2 text-sm border-s-4 border-s-emerald-400">
                    <h3 class="font-semibold text-gray-800">{{ __('التكلفة الشاملة') }}</h3>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('قيمة المخزون') }}</span><span class="font-bold tabular-nums text-emerald-700">{{ number_format($invoice->landed_subtotal, 2) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('ذمّة المورد') }}</span><span class="tabular-nums">{{ number_format($invoice->subtotal, 2) }}</span></div>
                    <div class="flex justify-between border-t border-gray-100 pt-2"><span class="text-gray-500">{{ __('مصاريف استيراد مستحقة') }}</span><span class="font-bold tabular-nums text-amber-700">{{ number_format($invoice->importDifference(), 2) }}</span></div>
                    <p class="text-xs text-gray-500 pt-1">
                        {{ __('دخلت البضاعة المخزونَ بتكلفتها الشاملة، وذمّة المورد بسعرها الحقيقي، والفرق التزامٌ في حساب «مصاريف استيراد مستحقة» يُطفأ عند وصول فاتورة الشحن والمصاريف.') }}
                    </p>
                </div>
            @endif

            @if ($invoice->status === 'posted' && $invoice->balanceDue() > 0)
                @can('purchasing.invoices.pay')
                    <div class="admin-card admin-card-pad">
                        <h3 class="font-semibold text-gray-800 mb-3">{{ __('تسجيل دفعة') }}</h3>
                        <form method="POST" action="{{ route('admin.purchasing.invoices.pay', $invoice) }}" class="space-y-3">
                            @csrf
                            <x-admin.field :label="__('من الخزنة/البنك')" name="treasury_id" required>
                                <select name="treasury_id" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                                    @foreach ($treasuries as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->currency }})</option>
                                    @endforeach
                                </select>
                            </x-admin.field>
                            <x-admin.field :label="__('المبلغ')" name="amount" required>
                                <input type="number" step="0.01" min="0.01" max="{{ $invoice->balanceDue() }}" name="amount" value="{{ $invoice->balanceDue() }}" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            </x-admin.field>
                            <button type="submit" class="btn-primary w-full">{{ __('دفع') }}</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    </div>
</x-app-layout>
