<x-app-layout :title="$shipment->number">
    @php
        $currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $variance = (float) $summary['variance'];
        $kindLabel = ['goods' => __('بضاعة'), 'expenses' => __('مصاريف')];
        $statusLabel = ['draft' => 'مسودّة', 'approved' => 'معتمدة', 'posted' => 'مُرحّلة', 'cancelled' => 'ملغاة', 'reversed' => 'معكوسة'];
        $statusTone = ['draft' => 'gray', 'approved' => 'blue', 'posted' => 'green', 'cancelled' => 'red', 'reversed' => 'amber'];
    @endphp

    <x-admin.header
        :title="__('شحنة :n', ['n' => $shipment->number])"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('شحنات الاستيراد') => route('admin.purchasing.shipments.index'), $shipment->number => null]">
        <div class="flex items-center gap-2 flex-wrap">
            @can('update', $shipment)
                <a href="{{ route('admin.purchasing.shipments.edit', $shipment) }}" class="btn-secondary btn-sm">{{ __('تعديل') }}</a>
            @endcan
            @can('reopen', $shipment)
                <x-admin.confirm :action="route('admin.purchasing.shipments.reopen', $shipment)" method="POST"
                    :title="__('إعادة فتح الشحنة')"
                    :message="__('سيُعكس قيد فرق التقدير ويعود الحساب الوسيط لحاله قبل الإغلاق.')"
                    :confirm="__('إعادة فتح')" tone="amber" :trigger="__('إعادة فتح')" />
            @endcan
            @can('delete', $shipment)
                @if ($invoices->isEmpty())
                    <x-admin.confirm :action="route('admin.purchasing.shipments.destroy', $shipment)" method="DELETE"
                        :title="__('حذف الشحنة')" :message="__('لا فواتير مرتبطة بها.')" :confirm="__('حذف')" tone="red" :trigger="__('حذف')" />
                @endif
            @endcan
        </div>
    </x-admin.header>

    <x-admin.flash />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="admin-card admin-card-pad">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><p class="text-gray-500">{{ __('رقم الكونتينر') }}</p><p class="font-medium text-gray-800">{{ $shipment->reference ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('المورد') }}</p><p class="font-medium text-gray-800">{{ $shipment->supplier?->name ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('الشحن') }}</p><p class="font-medium text-gray-800">{{ $shipment->shipped_at?->format('Y-m-d') ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('الوصول') }}</p><p class="font-medium text-gray-800">{{ $shipment->arrived_at?->format('Y-m-d') ?? '—' }}</p></div>
                    <div><p class="text-gray-500">{{ __('الحالة') }}</p><x-admin.badge :tone="$shipment->isOpen() ? 'amber' : 'green'" :label="$shipment->isOpen() ? __('مفتوحة') : __('مُغلقة')" /></div>
                    @unless ($shipment->isOpen())
                        <div><p class="text-gray-500">{{ __('أُغلقت في') }}</p><p class="font-medium text-gray-800">{{ $shipment->closed_at?->format('Y-m-d') }}</p></div>
                        <div><p class="text-gray-500">{{ __('بواسطة') }}</p><p class="font-medium text-gray-800">{{ $shipment->closer?->name ?? '—' }}</p></div>
                        @if ($shipment->varianceEntry)
                            <div><p class="text-gray-500">{{ __('قيد الفرق') }}</p><a href="{{ route('admin.accounting.journal.show', $shipment->varianceEntry) }}" class="font-mono text-emerald-600 hover:underline">{{ $shipment->varianceEntry->number }}</a></div>
                        @endif
                    @endunless
                </div>
                @if ($shipment->notes)
                    <p class="mt-4 pt-4 border-t border-gray-100 text-sm text-gray-600">{{ $shipment->notes }}</p>
                @endif
            </div>

            <x-admin.table :title="__('فواتير الشحنة')">
                <thead>
                    <tr>
                        <th>{{ __('الرقم') }}</th>
                        <th>{{ __('النوع') }}</th>
                        <th>{{ __('المورد') }}</th>
                        <th>{{ __('التاريخ') }}</th>
                        <th>{{ __('الحالة') }}</th>
                        <th class="text-start">{{ __('المبلغ') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr class="hover:bg-gray-50">
                            <td><a href="{{ route('admin.purchasing.invoices.show', $inv) }}" class="font-mono text-emerald-600 hover:underline">{{ $inv->number }}</a></td>
                            <td><x-admin.badge :tone="$inv->isExpenseInvoice() ? 'blue' : 'gray'" :label="$kindLabel[$inv->kind] ?? $inv->kind" /></td>
                            <td class="text-gray-700">{{ $inv->supplier?->name ?? '—' }}</td>
                            <td class="text-gray-500">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                            <td><x-admin.badge :tone="$statusTone[$inv->status] ?? 'gray'" :label="__($statusLabel[$inv->status] ?? $inv->status)" /></td>
                            <td class="text-start tabular-nums font-medium">{{ number_format($inv->subtotal, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10">
                            <x-admin.empty-state :title="__('لا فواتير على هذه الشحنة')"
                                :description="__('اربط بها فاتورة البضاعة أولًا، ثم فواتير الشحن البحري والجمارك حين تصل.')" />
                        </td></tr>
                    @endforelse
                </tbody>
            </x-admin.table>
        </div>

        {{-- شاشة الإغلاق: الأرقام أولًا، والقرار بعدها --}}
        <div class="space-y-6">
            <div class="admin-card admin-card-pad space-y-2 text-sm">
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('الحساب الوسيط') }}</h3>
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ __('التقدير المحمّل على البضاعة') }}</span>
                    <span class="tabular-nums font-medium">{{ number_format($summary['accrued'], 2) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ __('الفواتير الفعلية') }}</span>
                    <span class="tabular-nums font-medium">{{ number_format($summary['actual'], 2) }}</span>
                </div>
                <div class="flex justify-between border-t border-gray-100 pt-2 text-base">
                    <span class="font-semibold text-gray-700">{{ __('الفرق') }}</span>
                    <span class="font-bold tabular-nums {{ abs($variance) < 0.01 ? 'text-gray-400' : ($variance > 0 ? 'text-emerald-700' : 'text-rose-600') }}">
                        {{ number_format($variance, 2) }} {{ $currency }}
                    </span>
                </div>
                <p class="text-xs {{ $summary['over_tolerance'] ? 'text-amber-700 font-medium' : 'text-gray-400' }}">
                    @if (abs($variance) < 0.01)
                        {{ __('التقدير طابق الفعلي تمامًا.') }}
                    @elseif ($variance > 0)
                        {{ __('حُمّل على البضاعة أكثر من الواقع بنسبة :p٪.', ['p' => $summary['variance_pct']]) }}
                    @else
                        {{ __('حُمّل على البضاعة أقلّ من الواقع بنسبة :p٪.', ['p' => $summary['variance_pct']]) }}
                    @endif
                    @if ($summary['over_tolerance'])
                        {{ __('— تجاوز حدّ التسامح (:t٪).', ['t' => $tolerance]) }}
                    @endif
                </p>
            </div>

            <div class="admin-card admin-card-pad space-y-2 text-sm">
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('البضاعة') }}</h3>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('قيمة المخزون (شاملة)') }}</span><span class="tabular-nums">{{ number_format($summary['goods_value'], 2) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('ذمّة المورد') }}</span><span class="tabular-nums">{{ number_format($summary['supplier_value'], 2) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('الكمية المستلمة') }}</span><span class="tabular-nums">{{ rtrim(rtrim(number_format($summary['received_qty'], 3), '0'), '.') }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('المُباع تقديريًا') }}</span><span class="tabular-nums">{{ $summary['sold_ratio'] }}%</span></div>
                <p class="text-xs text-gray-400 pt-1">
                    {{ __('نسبة المُباع تقديرية: المخزون لا يُتتبَّع بالدفعات، فتُقارَن الكمية المستلمة بالمتوفّر الآن من أصنافها.') }}
                </p>
            </div>

            @can('close', $shipment)
                <div class="admin-card admin-card-pad">
                    <h3 class="font-semibold text-gray-800 mb-2">{{ __('إغلاق الشحنة') }}</h3>
                    <p class="text-xs text-gray-500 mb-3">
                        {{ __('يُقفل الفرق (:v) في حساب «فروق تقدير تكاليف الاستيراد»، ولا يُعاد به تسعير بضاعة بِيعت.', ['v' => number_format($variance, 2)]) }}
                    </p>
                    @if ($summary['over_tolerance'])
                        <x-admin.alert tone="amber" class="mb-3">
                            {{ __('الفرق :p٪ — راجع فواتير المصاريف قبل الإغلاق؛ قد تكون فاتورة لم تصل بعد.', ['p' => $summary['variance_pct']]) }}
                        </x-admin.alert>
                    @endif
                    <form method="POST" action="{{ route('admin.purchasing.shipments.close', $shipment) }}" class="space-y-3">
                        @csrf
                        <textarea name="notes" rows="2" maxlength="500" placeholder="{{ __('ملاحظة الإغلاق (اختيارية)') }}"
                                  class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                        <button type="submit" class="btn-primary w-full">{{ __('إغلاق الشحنة وترحيل الفرق') }}</button>
                    </form>
                    <p class="mt-3 text-xs text-gray-400">
                        {{ __('إعادة تقييم الأصناف المتبقّية في المخزن بالفرق تأتي في مرحلة لاحقة؛ الإغلاق الآن يُرحّله لحساب النتيجة.') }}
                    </p>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
