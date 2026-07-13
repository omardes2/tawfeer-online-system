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
            @if ($invoice->status === 'draft')
                @can('purchasing.invoices.approve')
                    <form method="POST" action="{{ route('admin.purchasing.invoices.approve', $invoice) }}">@csrf<button class="btn-primary btn-sm">{{ __('اعتماد') }}</button></form>
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

            <x-admin.table :title="__('البنود')">
                <thead><tr><th>{{ __('الصنف') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('التكلفة') }}</th><th>{{ __('ضريبة') }}</th><th class="text-start">{{ __('الإجمالي') }}</th></tr></thead>
                <tbody>
                    @foreach ($invoice->items as $it)
                        <tr>
                            <td class="text-gray-800">{{ $it->variant?->product?->name ?? $it->description ?? $it->variant?->sku ?? '—' }}</td>
                            <td class="tabular-nums">{{ rtrim(rtrim(number_format($it->qty, 3), '0'), '.') }}</td>
                            <td class="tabular-nums">{{ number_format($it->unit_cost, 2) }}</td>
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
