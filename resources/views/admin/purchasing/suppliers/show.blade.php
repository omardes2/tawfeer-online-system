<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المورد') }} — {{ $supplier->name }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))
    @php($statusLabels = ['draft' => __('مسودّة'), 'approved' => __('معتمدة'), 'posted' => __('مُرحّلة'), 'cancelled' => __('ملغاة'), 'reversed' => __('معكوسة')])

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4" x-data="{ tab: 'invoices' }">
        <x-admin.flash />

        {{-- شريط علوي: عودة + إجراءات --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('admin.purchasing.suppliers.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('عودة للموردين') }}
            </a>
            <div class="flex items-center gap-2">
                @can('update', $supplier)
                    <a href="{{ route('admin.purchasing.suppliers.edit', $supplier) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50">{{ __('تعديل') }}</a>
                @endcan
                @can('create', \App\Modules\Purchasing\Models\PurchaseInvoice::class)
                    <a href="{{ route('admin.purchasing.invoices.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('فاتورة جديدة') }}</a>
                @endcan
            </div>
        </div>

        {{-- بطاقة رأس المورد + ملخص الأرصدة --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-col lg:flex-row lg:items-center gap-6">
                <div class="flex items-center gap-4 flex-1">
                    <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-50 text-emerald-600 text-xl font-bold shrink-0">{{ mb_substr($supplier->name, 0, 1) }}</span>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-xl font-bold text-gray-900">{{ $supplier->name }}</h3>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $supplier->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $supplier->is_active ? __('نشط') : __('غير نشط') }}</span>
                        </div>
                        <div class="text-sm text-gray-500 mt-0.5 space-y-0.5">
                            <div>{{ __('الرمز') }}: <span class="text-gray-700">{{ $supplier->code }}</span></div>
                            @if ($supplier->tax_number)<div>{{ __('الرقم الضريبي') }}: <span class="text-gray-700">{{ $supplier->tax_number }}</span></div>@endif
                            @if ($supplier->glAccount)<div>{{ __('الحساب المحاسبي') }}: <span class="text-gray-700 font-mono">{{ $supplier->glAccount->code }}</span> <span class="text-gray-400">— {{ $supplier->glAccount->name }}</span></div>@endif
                        </div>
                    </div>
                </div>

                {{-- بطاقات الملخص --}}
                <div class="grid grid-cols-3 gap-3 lg:w-[420px]">
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('إجمالي المشتريات') }}</div>
                        <div class="text-base font-bold text-gray-900 tabular-nums mt-1">{{ number_format($invoiced, 2) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('المدفوعات') }}</div>
                        <div class="text-base font-bold text-emerald-600 tabular-nums mt-1">{{ number_format($paid, 2) }}</div>
                        {{--
                            ما أطفأ الذمّة زيادةً على النقد الخارج: فرقُ صرفٍ عند
                            السداد بالعملة الأجنبية، أو مرتجعٌ، أو قيد تسوية.
                            يُعرض صراحةً — وإخفاؤه هو ما جعل هذه البطاقة تخالف
                            رصيد القائمة بلا تفسير.
                        --}}
                        @if (abs($adjustments) >= 0.01)
                            <div class="text-[11px] leading-tight text-gray-500 mt-0.5"
                                 title="{{ __('فروق صرف أو مرتجعات أو قيود تسوية على حساب المورد') }}">
                                {{ $adjustments > 0 ? '+' : '−' }}{{ number_format(abs($adjustments), 2) }}
                                {{ __('تسويات') }}
                            </div>
                        @endif
                    </div>
                    <div class="rounded-lg p-3 text-center {{ abs($balance) < 0.01 ? 'bg-gray-50' : 'bg-rose-50' }}">
                        <div class="text-xs text-gray-500">{{ __('الرصيد المتبقّي') }}</div>
                        <div class="text-base font-bold tabular-nums mt-1 {{ abs($balance) < 0.01 ? 'text-gray-900' : 'text-rose-600' }}">{{ number_format($balance, 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- التبويبات --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center gap-1 border-b border-gray-100 px-4 pt-3">
                @php($tabs = ['invoices' => __('الفواتير'), 'payments' => __('الدفعات'), 'statement' => __('كشف الحساب'), 'details' => __('التفاصيل')])
                @foreach ($tabs as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition">{{ $label }}</button>
                @endforeach
            </div>

            {{-- تبويب الفواتير --}}
            <div x-show="tab === 'invoices'" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('رقم الفاتورة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الإجمالي') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المدفوع') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المتبقّي') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($invoices as $inv)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4"><a href="{{ route('admin.purchasing.invoices.show', $inv) }}" class="text-emerald-700 font-medium hover:underline">{{ $inv->number }}</a></td>
                                <td class="py-3 px-4 text-gray-500">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ number_format((float) $inv->total, 2) }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-500">{{ number_format((float) $inv->amount_paid, 2) }}</td>
                                <td class="py-3 px-4 tabular-nums font-medium {{ ((float) $inv->total - (float) $inv->amount_paid) > 0.01 ? 'text-rose-600' : 'text-gray-400' }}">{{ number_format((float) $inv->total - (float) $inv->amount_paid, 2) }}</td>
                                <td class="py-3 px-4 text-center"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600">{{ $statusLabels[$inv->status] ?? $inv->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-gray-400">{{ __('لا توجد فواتير لهذا المورد.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $invoices->links() }}</div>
            </div>

            {{-- تبويب الدفعات --}}
            <div x-show="tab === 'payments'" class="overflow-x-auto" style="display:none">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('رقم السند') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الخزنة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المبلغ') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($payments as $pay)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-800 font-medium">{{ $pay->number }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $pay->voucher_date?->format('Y-m-d') }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $pay->treasury?->name ?? '—' }}</td>
                                <td class="py-3 px-4 tabular-nums text-emerald-600 font-medium">{{ number_format((float) $pay->amount, 2) }}</td>
                                <td class="py-3 px-4 text-center"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600">{{ $statusLabels[$pay->status] ?? $pay->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-gray-400">{{ __('لا توجد دفعات لهذا المورد.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $payments->links() }}</div>
            </div>

            {{-- تبويب كشف الحساب --}}
            <div x-show="tab === 'statement'" class="overflow-x-auto" style="display:none">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('البيان') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('مدين (دفعات)') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('دائن (فواتير)') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الرصيد') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($statement as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-500">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y-m-d') }}</td>
                                {{--
                                    الوسم يقول نوع الحركة: الكشف يحمل الآن ما
                                    يحمله الدفتر — رصيدًا افتتاحيًّا وفروقَ صرفٍ
                                    وقيودَ تسوية، لا فواتيرَ ودفعاتٍ فقط.
                                --}}
                                @php($badge = [
                                    'invoice' => ['bg-amber-50 text-amber-700', __('فاتورة')],
                                    'payment' => ['bg-emerald-50 text-emerald-700', __('دفعة')],
                                    'fx' => ['bg-sky-50 text-sky-700', __('فرق صرف')],
                                    'opening' => ['bg-violet-50 text-violet-700', __('رصيد افتتاحي')],
                                ][$row['type']] ?? ['bg-gray-100 text-gray-600', __('حركة')])
                                <td class="py-3 px-4 text-gray-800">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs me-1 {{ $badge[0] }}">{{ $badge[1] }}</span>
                                    {{ $row['ref'] }}
                                </td>
                                <td class="py-3 px-4 tabular-nums text-emerald-600">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' }}</td>
                                <td class="py-3 px-4 tabular-nums text-rose-600">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' }}</td>
                                <td class="py-3 px-4 tabular-nums font-medium text-gray-900">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-gray-400">{{ __('لا توجد حركات في كشف الحساب.') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if ($statement->isNotEmpty())
                        <tfoot class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td colspan="4" class="py-3 px-4 text-start font-bold text-gray-700">{{ __('الرصيد المتبقّي') }}</td>
                                <td class="py-3 px-4 tabular-nums font-bold {{ abs($balance) < 0.01 ? 'text-gray-900' : 'text-rose-600' }}">{{ number_format($balance, 2) }} {{ $currency }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- تبويب التفاصيل --}}
            <div x-show="tab === 'details'" class="p-6 space-y-6" style="display:none">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('الاسم القانوني') }}</span><span class="text-gray-800">{{ $supplier->legal_name ?: '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('الرقم الضريبي') }}</span><span class="text-gray-800">{{ $supplier->tax_number ?: '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('الهاتف') }}</span><span class="text-gray-800" dir="ltr">{{ $supplier->phone ?: '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('البريد الإلكتروني') }}</span><span class="text-gray-800">{{ $supplier->email ?: '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('العنوان') }}</span><span class="text-gray-800">{{ $supplier->address ?: '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('مهلة السداد (يوم)') }}</span><span class="text-gray-800">{{ $supplier->payment_terms_days ?? '—' }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2"><span class="text-gray-500">{{ __('سقف الائتمان') }}</span><span class="text-gray-800 tabular-nums">{{ number_format((float) $supplier->credit_limit, 2) }}</span></div>
                    <div class="flex justify-between border-b border-gray-50 pb-2">
                        <span class="text-gray-500">{{ __('الرصيد الافتتاحي') }}</span>
                        <span class="text-gray-800 tabular-nums">
                            {{ number_format((float) $supplier->opening_balance, 2) }}
                            {{-- رقمٌ بلا قيد يُقرأ رصيدًا وهو ليس في الدفاتر — يُوسَم حتى يُرحَّل. --}}
                            @if ($supplier->hasUnpostedOpening())
                                <span class="ms-1 text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">{{ __('غير مُرحّل') }}</span>
                            @endif
                        </span>
                    </div>
                </div>

                @if ($supplier->notes)
                    <div class="text-sm"><div class="text-gray-500 mb-1">{{ __('ملاحظات') }}</div><p class="text-gray-800 whitespace-pre-line">{{ $supplier->notes }}</p></div>
                @endif

                {{-- جهات الاتصال --}}
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">{{ __('جهات الاتصال') }}</h4>
                    <div class="overflow-x-auto border border-gray-100 rounded-lg">
                        <table class="min-w-full text-sm">
                            <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="py-2.5 px-4 font-medium text-start">{{ __('الاسم') }}</th>
                                    <th class="py-2.5 px-4 font-medium text-start">{{ __('الهاتف') }}</th>
                                    <th class="py-2.5 px-4 font-medium text-start">{{ __('البريد') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($supplier->contacts as $c)
                                    <tr>
                                        <td class="py-2.5 px-4 text-gray-800">{{ $c->name }}</td>
                                        <td class="py-2.5 px-4 text-gray-500" dir="ltr">{{ $c->phone ?: '—' }}</td>
                                        <td class="py-2.5 px-4 text-gray-500">{{ $c->email ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="py-6 text-center text-gray-400">{{ __('لا توجد جهات اتصال.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
