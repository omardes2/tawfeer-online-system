<x-app-layout :title="__('تقارير المبيعات')">
    <x-admin.header
        :title="__('تقارير المبيعات')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('المبيعات') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); $s = $data['summary']; @endphp

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <x-admin.stat-card :label="__('عدد الطلبات')" :value="number_format($s['orders'])" tone="blue" />
        <x-admin.stat-card :label="__('الإيراد')" :value="$s['revenue']" tone="green" money />
        <x-admin.stat-card :label="__('التكلفة')" :value="$s['cost']" tone="amber" money />
        <x-admin.stat-card :label="__('الربح')" :value="$s['profit']" :tone="$s['profit'] >= 0 ? 'green' : 'red'" money />
        <x-admin.stat-card :label="__('هامش الربح')" :value="$s['margin'].'%'" tone="green" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-card p-4 lg:col-span-2">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('المبيعات اليومية') }}</h3>
            @if ($data['daily']->isNotEmpty())
                <x-admin.chart type="area" :labels="$data['daily']->pluck('d')" :datasets="[['label' => __('المبيعات'), 'data' => $data['daily']->pluck('t')]]" />
            @else <x-admin.empty-state :title="__('لا توجد مبيعات')" /> @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('حسب مصدر الطلب') }}</h3>
            @if ($data['by_channel']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260" :labels="$data['by_channel']->pluck('channel')" :datasets="[['data' => $data['by_channel']->pluck('t')]]" />
            @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
        </div>
    </div>

    @php
        $tables = [
            ['المبيعات حسب الفئة', $data['by_category'], ['الفئة' => 'name', 'الكمية' => 'qty', 'الإيراد' => 'revenue', 'الربح' => 'profit']],
            ['المبيعات حسب العلامة التجارية', $data['by_brand'], ['العلامة' => 'name', 'الكمية' => 'qty', 'الإيراد' => 'revenue', 'الربح' => 'profit']],
            ['أفضل المنتجات', $data['by_product'], ['المنتج' => 'name', 'الكمية' => 'qty', 'الإيراد' => 'revenue', 'الربح' => 'profit']],
            ['المبيعات حسب المدينة', $data['by_city'], ['المدينة' => 'name', 'الطلبات' => 'c', 'المبيعات' => 't']],
            ['المبيعات حسب الموظف', $data['by_employee'], ['الموظف' => 'name', 'الطلبات' => 'c', 'المبيعات' => 't']],
            ['المبيعات حسب المسوّق', $data['by_marketer'], ['المسوّق' => 'name', 'الطلبات' => 'c', 'المبيعات' => 't']],
            ['حسب حالة الطلب', $data['by_status'], ['الحالة' => 'status', 'العدد' => 'c', 'الإجمالي' => 't']],
            ['حسب حالة الدفع', $data['by_payment'], ['الحالة' => 'payment_status', 'العدد' => 'c', 'الإجمالي' => 't']],
        ];
        $moneyCols = ['revenue', 'profit', 't'];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ($tables as [$title, $rows, $cols])
            <x-admin.table :title="__($title)">
                <thead><tr>@foreach ($cols as $h => $k)<th>{{ __($h) }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            @foreach ($cols as $h => $k)
                                <td class="{{ $k === 'name' || $k === 'status' || $k === 'payment_status' ? 'font-medium text-gray-800' : 'tabular-nums' }}">
                                    {{ in_array($k, $moneyCols) ? number_format((float) $r->$k, 2).' '.$cur : ($r->$k ?? '—') }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($cols) }}" class="!p-0"><x-admin.empty-state :title="__('لا توجد بيانات')" /></td></tr>
                    @endforelse
                </tbody>
            </x-admin.table>
        @endforeach
    </div>
</x-app-layout>
