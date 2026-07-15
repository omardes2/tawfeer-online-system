<x-app-layout :title="__('تقارير التسويق')">
    <x-admin.header
        :title="__('تقارير التسويق')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('التسويق') => null]" />

    <x-admin.report-filters :range="$range" :exports="false" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الحملات')" :value="number_format($data['campaigns_total'])" tone="blue" />
        <x-admin.stat-card :label="__('طلبات الفترة (كل المصادر)')" :value="number_format($data['orders_by_source']->sum('c'))" tone="green" />
        <x-admin.stat-card :label="__('مبيعات الفترة')" :value="$data['orders_by_source']->sum('t')" tone="green" money />
        <x-admin.stat-card :label="__('عدد القنوات')" :value="number_format($data['by_channel']->count())" tone="amber" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الطلبات حسب المصدر') }}</h3>
            @if ($data['orders_by_source']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260" :labels="$data['orders_by_source']->pluck('channel')" :datasets="[['data' => $data['orders_by_source']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد طلبات')" /> @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الحملات حسب القناة') }}</h3>
            @if ($data['by_channel']->isNotEmpty())
                <x-admin.chart type="bar" :height="260" :labels="$data['by_channel']->pluck('channel')" :datasets="[['label' => __('الحملات'), 'data' => $data['by_channel']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد حملات')" /> @endif
        </div>
    </div>

    <x-admin.table :title="__('الحملات')">
        <thead><tr><th>{{ __('الحملة') }}</th><th>{{ __('القناة') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('النوع') }}</th><th>{{ __('التاريخ') }}</th></tr></thead>
        <tbody>
            @forelse ($data['campaigns'] as $r)
                <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="text-xs">{{ $r->channel }}</td><td><x-admin.badge :label="$r->status" :tone="$r->status === 'active' ? 'green' : 'gray'" /></td><td class="text-xs text-gray-500">{{ $r->use_case }}</td><td class="text-gray-500 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d') }}</td></tr>
            @empty <tr><td colspan="5" class="!p-0"><x-admin.empty-state :title="__('لا توجد حملات')" /></td></tr> @endforelse
        </tbody>
    </x-admin.table>

    <p class="mt-4 text-xs text-gray-400">{{ __('ملاحظة: مقاييس التكلفة لكل حملة (CPA/ROAS) وربط الحملات بالطلبات تتطلّب تتبّع معرّف الحملة على الطلب — تُضاف كتوسعة لاحقة.') }}</p>
</x-app-layout>
