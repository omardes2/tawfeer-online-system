<x-app-layout :title="__('المبيعات حسب الزبون')">
    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp
    <div class="report-no-print">
        <x-admin.header
            :title="__('المبيعات حسب الزبون')"
            :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير') => null, __('المبيعات حسب الزبون') => null]" />
    </div>

    @include('admin.reports.business._toolbar', ['title' => __('المبيعات حسب الزبون')])

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('الزبون') }}</th>
                <th class="text-start">{{ __('عدد الطلبات') }}</th>
                <th class="text-start">{{ __('مجموع المبيعات') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-start tabular-nums">{{ number_format($r['orders_count']) }}</td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($r['sales_total'], 2) }} {{ $sym }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="!p-0">
                    <x-admin.empty-state :title="__('لا توجد مبيعات')" :description="__('لم تُسجّل أي مبيعات بعد.')" />
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

    {{-- الرقم هنا **يشمل التوصيل**: هو ما يُحصَّل من الزبون لا ما بِيع من بضاعة --}}
    @include('admin.reports.business._basis', [
        'basisIncludes' => [
            __('قيمة البضاعة بعد الخصم'),
            __('رسوم التوصيل'),
            __('الضريبة إن وُجدت'),
            __('كل الطلبات المؤكّدة فأكثر — بموظف أو بغيره'),
        ],
        'basisExcludes' => [
            __('الطلبات المسودّة والجديدة والملغاة'),
        ],
        'basisNote' => __('هذا الرقم يجيب على «كم حُصِّل من الزبائن؟». لمعرفة قيمة البضاعة المباعة وحدها راجع «المبيعات حسب المنتج» — الفرق بينهما هو رسوم التوصيل، وهي تُحصَّل لصالح شركة التوصيل لا كإيراد لك.'),
    ])
</x-app-layout>
