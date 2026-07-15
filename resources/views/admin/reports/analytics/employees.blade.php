<x-app-layout :title="$title">
    <x-admin.header
        :title="$title"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), $title => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الطلبات')" :value="number_format($data['total_orders'])" tone="blue" />
        <x-admin.stat-card :label="__('إجمالي الإيراد')" :value="$data['total_revenue']" tone="green" money />
        <x-admin.stat-card :label="__('إجمالي العمولات')" :value="$data['total_commission']" tone="amber" money />
        <x-admin.stat-card :label="__('عدد النشطين')" :value="number_format($data['rows']->count())" tone="blue" />
    </div>

    <div class="admin-card p-4 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">{{ __('ترتيب الأداء (بالإيراد)') }}</h3>
        @if ($data['rows']->isNotEmpty())
            <x-admin.chart type="bar" horizontal :labels="$data['rows']->take(12)->pluck('name')" :datasets="[['label' => __('الإيراد'), 'data' => $data['rows']->take(12)->pluck('revenue')]]" />
        @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
    </div>

    <x-admin.table :title="__('التفصيل')">
        <thead><tr>
            <th>#</th><th>{{ $roleLabel }}</th><th>{{ __('الطلبات') }}</th><th>{{ __('الإيراد') }}</th><th>{{ __('الربح') }}</th><th>{{ __('العمولة') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($data['rows'] as $i => $r)
                <tr>
                    <td class="tabular-nums text-gray-400">{{ $i + 1 }}</td>
                    <td class="font-medium text-gray-800">{{ $r->name }}</td>
                    <td class="tabular-nums">{{ number_format($r->orders_count) }}</td>
                    <td class="tabular-nums">{{ number_format($r->revenue, 2) }} {{ $cur }}</td>
                    <td class="tabular-nums text-emerald-600">{{ number_format($r->profit, 2) }} {{ $cur }}</td>
                    <td class="tabular-nums text-amber-600">{{ number_format($r->commission, 2) }} {{ $cur }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0"><x-admin.empty-state :title="__('لا توجد بيانات في هذه الفترة')" /></td></tr>
            @endforelse
        </tbody>
    </x-admin.table>
</x-app-layout>
