<x-app-layout :title="__('فواتير الشراء')">
    <x-admin.header
        :title="__('فواتير الموردين')"
        :description="__('تسجيل فواتير المشتريات ومتابعة المستحقّ للموردين (ذمم دائنة).')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المخزون والمشتريات') => null, __('فواتير الشراء') => null]">
        @can('purchasing.invoices.create')
            <a href="{{ route('admin.purchasing.invoices.create') }}" class="btn-primary btn-sm">{{ __('فاتورة جديدة') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الفواتير')" :value="$totalCount" tone="blue"
            :icon="'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'" />
        <x-admin.stat-card :label="__('المستحقّ للموردين')" :value="$outstanding ?? 0" money tone="amber"
            :icon="'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z'" />
    </div>

    {{-- فلتر الحالات --}}
    @php
        $labels = ['draft' => 'مسودّة', 'approved' => 'معتمدة', 'posted' => 'مُرحّلة', 'cancelled' => 'ملغاة', 'reversed' => 'معكوسة'];
        $tabs = [['key' => null, 'label' => __('الكل'), 'count' => $totalCount, 'active' => $activeStatus === null]];
        foreach ($statuses as $s) {
            $tabs[] = ['key' => $s, 'label' => __($labels[$s] ?? $s), 'count' => (int) ($statusCounts[$s] ?? 0), 'active' => $activeStatus === $s];
        }
    @endphp
    <div class="flex items-center gap-2 flex-wrap mb-4">
        @foreach ($tabs as $t)
            <a href="{{ $t['key'] ? route('admin.purchasing.invoices.index', ['status' => $t['key']]) : route('admin.purchasing.invoices.index') }}"
               @class(['inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition border', 'bg-emerald-600 text-white border-emerald-600' => $t['active'], 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' => !$t['active']])>
                <span>{{ $t['label'] }}</span>
                <span @class(['text-xs rounded-full px-1.5 min-w-[1.25rem] text-center', 'bg-white/20 text-white' => $t['active'], 'bg-gray-100 text-gray-500' => !$t['active']])>{{ $t['count'] }}</span>
            </a>
        @endforeach
    </div>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('رقم الفاتورة') }}</th>
                <th>{{ __('المورد') }}</th>
                <th>{{ __('التاريخ') }}</th>
                <th class="text-start">{{ __('الإجمالي') }}</th>
                <th class="text-start">{{ __('المتبقّي') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th>{{ __('الدفع') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @php
                $statusTone = ['draft' => 'gray', 'approved' => 'blue', 'posted' => 'green', 'cancelled' => 'red', 'reversed' => 'amber'];
                $payTone = ['paid' => 'green', 'partial' => 'amber', 'unpaid' => 'red'];
                $payLabel = ['paid' => 'مدفوعة', 'partial' => 'جزئية', 'unpaid' => 'غير مدفوعة'];
            @endphp
            @forelse ($invoices as $inv)
                <tr>
                    <td class="font-mono text-xs text-gray-800">{{ $inv->number }}</td>
                    <td class="font-medium text-gray-800">{{ $inv->supplier?->name }}</td>
                    <td class="text-gray-500 whitespace-nowrap">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($inv->total, 2) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap {{ $inv->balanceDue() > 0 ? 'text-rose-600' : 'text-gray-400' }}">{{ number_format($inv->balanceDue(), 2) }}</td>
                    <td><x-admin.badge :tone="$statusTone[$inv->status] ?? 'gray'" :label="__($labels[$inv->status] ?? $inv->status)" /></td>
                    <td><x-admin.badge :tone="$payTone[$inv->payment_status] ?? 'gray'" :label="__($payLabel[$inv->payment_status] ?? $inv->payment_status)" /></td>
                    <td class="text-end whitespace-nowrap">
                        <div class="inline-flex items-center gap-2 text-sm">
                            <a href="{{ route('admin.purchasing.invoices.show', $inv) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a>
                            @php $editable = \App\Http\Controllers\Admin\Purchasing\PurchaseInvoiceController::isEditable($inv); @endphp
                            @can('purchasing.invoices.create')
                                @if ($editable)
                                    <span class="text-gray-300">|</span>
                                    <a href="{{ route('admin.purchasing.invoices.edit', $inv) }}" class="text-sky-600 hover:underline">{{ __('تعديل') }}</a>
                                @endif
                            @endcan
                            @can('purchasing.invoices.delete')
                                @if (\App\Http\Controllers\Admin\Purchasing\PurchaseInvoiceController::isDeletable($inv))
                                    <span class="text-gray-300">|</span>
                                    <form method="POST" action="{{ route('admin.purchasing.invoices.destroy', $inv) }}" class="inline"
                                          onsubmit="return confirm('{{ __('حذف الفاتورة؟ ستُعكس دفعاتها (يعود المال للخزينة) وتُسحب بضاعتها من المخزون ويُعكس قيدها المحاسبي.') }}')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد فواتير شراء')" :description="__('ابدأ بتسجيل أول فاتورة مورد.')"
                        :icon="'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4">{{ $invoices->links() }}</div>
</x-app-layout>
