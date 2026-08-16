<x-app-layout :title="$reportTitle">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp
    <div class="report-no-print">
        <x-admin.header
            :title="$reportTitle"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, $reportTitle => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => $reportTitle])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ $personLabel }}</th>
                <th class="text-start">{{ __('عدد الطلبات') }}</th>
                <th class="text-start">{{ __('إجمالي المبيعات (من غير توصيل)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-start tabular-nums">{{ number_format($r['orders_count']) }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($r['sales'], 2) }} {{ $sym }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد مبيعات')" :description="$emptyDescription" />
                </td></tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="font-bold bg-gray-50">
                    <td>{{ __('الإجمالي') }}</td>
                    <td class="text-start tabular-nums">{{ number_format($totalOrders) }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap">{{ number_format($totalSales, 2) }} {{ $sym }}</td>
                </tr>
            </tfoot>
        @endif
    </x-admin.table>

    {{--
        الاستثناء الأهمّ هنا: الطلب بلا شخصٍ مرتبط لا يظهر في أي صفّ **ولا في
        المجموع**. فمجموع هذا التقرير أقلّ من مبيعات اليوم بالضرورة، وذِكرُ ذلك
        صراحةً يمنع قراءته على أنه إجمالي المتجر.
    --}}
    @include('admin.reports.business._basis', [
        'basisIncludes' => [
            __('قيمة البضاعة قبل الخصم'),
            __('الطلبات المرتبطة ب:person فقط', ['person' => $personLabel]),
        ],
        'basisExcludes' => [
            __('رسوم التوصيل'),
            __('الطلبات المسودّة والجديدة والملغاة'),
            __('الطلبات غير المرتبطة ب:person — طلبات المتجر الإلكتروني وما ينشئه المدير', ['person' => $personLabel]),
        ],
        'basisNote' => __('مجموع هذا التقرير أقلّ من مبيعات الفترة بالضرورة، لأن الطلبات غير المرتبطة ب:person لا تدخله. لمعرفة إجمالي المبيعات راجع «المبيعات حسب المنتج».', ['person' => $personLabel]),
    ])
</x-app-layout>
