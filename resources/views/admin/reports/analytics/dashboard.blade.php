<x-app-layout :title="__('اللوحة التنفيذية')">
    <x-admin.header
        :title="__('اللوحة التنفيذية')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('اللوحة التنفيذية') => null]" />

    <x-admin.report-filters :range="$range" :exports="false" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    {{-- مبيعات الفترات --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <x-admin.stat-card :label="__('مبيعات اليوم')" :value="$d['sales_today']" tone="green" money icon="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
        <x-admin.stat-card :label="__('مبيعات الأسبوع')" :value="$d['sales_week']" tone="green" money />
        <x-admin.stat-card :label="__('مبيعات الشهر')" :value="$d['sales_month']" tone="green" money />
        <x-admin.stat-card :label="__('مبيعات السنة')" :value="$d['sales_year']" tone="green" money />
    </div>

    {{-- مؤشّرات الفترة المختارة --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <x-admin.stat-card :label="__('عدد الطلبات')" :value="number_format($d['orders'])" tone="blue" icon="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272" />
        <x-admin.stat-card :label="__('متوسّط قيمة الطلب')" :value="$d['aov']" tone="blue" money />
        <x-admin.stat-card :label="__('إجمالي الإيراد')" :value="$d['gross_revenue']" tone="green" money />
        <x-admin.stat-card :label="__('صافي الربح')" :value="$d['net_profit']" :tone="$d['net_profit'] >= 0 ? 'green' : 'red'" money :hint="__('بعد العمولات والشحن')" />
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي العملاء')" :value="number_format($d['customers'])" tone="blue" />
        <x-admin.stat-card :label="__('عملاء جدد')" :value="number_format($d['new_customers'])" tone="green" />
        <x-admin.stat-card :label="__('أصناف منخفضة المخزون')" :value="number_format($d['low_stock'])" tone="amber" />
        <x-admin.stat-card :label="__('أصناف نفدت')" :value="number_format($d['out_of_stock'])" tone="red" />
    </div>

    {{-- الرسوم --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-card p-4 lg:col-span-2">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('اتجاه المبيعات اليومي') }}</h3>
            @if ($d['daily']->isNotEmpty())
                <x-admin.chart type="area"
                    :labels="$d['daily']->pluck('d')"
                    :datasets="[['label' => __('المبيعات'), 'data' => $d['daily']->pluck('t')]]" />
            @else
                <x-admin.empty-state :title="__('لا توجد مبيعات في هذه الفترة')" />
            @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الطلبات حسب الحالة') }}</h3>
            @if ($d['by_status']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260"
                    :labels="$d['by_status']->pluck('status')"
                    :datasets="[['label' => __('الطلبات'), 'data' => $d['by_status']->pluck('c')]]" />
            @else
                <x-admin.empty-state :title="__('لا توجد طلبات')" />
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('المبيعات حسب المدينة') }}</h3>
            @if ($d['by_city']->isNotEmpty())
                <x-admin.chart type="bar" horizontal :height="260"
                    :labels="$d['by_city']->pluck('name')"
                    :datasets="[['label' => __('المبيعات'), 'data' => $d['by_city']->pluck('t')]]" />
            @else
                <x-admin.empty-state :title="__('لا توجد بيانات')" />
            @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('المبيعات حسب الموظف') }}</h3>
            @if ($d['by_employee']->isNotEmpty())
                <x-admin.chart type="bar" horizontal :height="260"
                    :labels="$d['by_employee']->pluck('name')"
                    :datasets="[['label' => __('المبيعات'), 'data' => $d['by_employee']->pluck('t'), 'color' => '#0ea5e9']]" />
            @else
                <x-admin.empty-state :title="__('لا توجد بيانات')" />
            @endif
        </div>
    </div>

    {{-- أداء الشحن --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الشحنات')" :value="number_format($d['shipping']['total'])" tone="blue" />
        <x-admin.stat-card :label="__('مُسلّمة')" :value="number_format($d['shipping']['delivered'])" tone="green" />
        <x-admin.stat-card :label="__('مرتجعة')" :value="number_format($d['shipping']['returned'])" tone="red" />
        <x-admin.stat-card :label="__('نسبة النجاح')" :value="$d['shipping']['success_rate'].'%'" tone="green" />
        <x-admin.stat-card :label="__('متوسّط زمن التسليم')" :value="$d['shipping']['avg_days'].' '.__('يوم')" tone="amber" />
    </div>

    {{-- أفضل/أسوأ المنتجات --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('الأكثر مبيعًا')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('الإيراد') }}</th><th>{{ __('الربح') }}</th></tr></thead>
            <tbody>
                @forelse ($d['best_products'] as $p)
                    <tr><td class="font-medium text-gray-800">{{ $p->name }}</td><td class="tabular-nums">{{ number_format($p->qty) }}</td><td class="tabular-nums">{{ number_format($p->revenue, 2) }} {{ $cur }}</td><td class="tabular-nums text-emerald-600">{{ number_format($p->profit, 2) }} {{ $cur }}</td></tr>
                @empty
                    <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا توجد مبيعات')" /></td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('الأقل مبيعًا')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('الإيراد') }}</th></tr></thead>
            <tbody>
                @forelse ($d['worst_products'] as $p)
                    <tr><td class="font-medium text-gray-800">{{ $p->name }}</td><td class="tabular-nums">{{ number_format($p->qty) }}</td><td class="tabular-nums">{{ number_format($p->revenue, 2) }} {{ $cur }}</td></tr>
                @empty
                    <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا توجد مبيعات')" /></td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
