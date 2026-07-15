<x-app-layout :title="__('تقارير الشحن والتوصيل')">
    <x-admin.header
        :title="__('تقارير الشحن والتوصيل')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('الشحن') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); $p = $data['performance']; @endphp

    <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي الشحنات')" :value="number_format($p['total'])" tone="blue" />
        <x-admin.stat-card :label="__('مُسلّمة')" :value="number_format($p['delivered'])" tone="green" />
        <x-admin.stat-card :label="__('مرتجعة')" :value="number_format($p['returned'])" tone="red" />
        <x-admin.stat-card :label="__('قيد التوصيل')" :value="number_format($p['pending'])" tone="amber" />
        <x-admin.stat-card :label="__('نسبة النجاح')" :value="$p['success_rate'].'%'" tone="green" />
        <x-admin.stat-card :label="__('تكلفة الشحن')" :value="$p['cost']" tone="amber" money />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الشحنات حسب الحالة') }}</h3>
            @if ($data['by_status']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260" :labels="$data['by_status']->pluck('status')" :datasets="[['data' => $data['by_status']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد شحنات')" /> @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الشحنات حسب المدينة') }}</h3>
            @if ($data['by_city']->isNotEmpty())
                <x-admin.chart type="bar" horizontal :height="260" :labels="$data['by_city']->pluck('name')" :datasets="[['label' => __('الشحنات'), 'data' => $data['by_city']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('أداء شركات التوصيل')">
            <thead><tr><th>{{ __('الشركة') }}</th><th>{{ __('الشحنات') }}</th><th>{{ __('مُسلّمة') }}</th><th>{{ __('مرتجعة') }}</th></tr></thead>
            <tbody>
                @forelse ($data['by_provider'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ $r->c }}</td><td class="tabular-nums text-emerald-600">{{ $r->delivered }}</td><td class="tabular-nums text-rose-600">{{ $r->returned }}</td></tr>
                @empty <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا توجد بيانات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('أسباب الإرجاع')">
            <thead><tr><th>{{ __('السبب') }}</th><th>{{ __('العدد') }}</th></tr></thead>
            <tbody>
                @forelse ($data['return_reasons'] as $r)
                    <tr><td class="text-gray-800">{{ $r->reason }}</td><td class="tabular-nums">{{ $r->c }}</td></tr>
                @empty <tr><td colspan="2" class="!p-0"><x-admin.empty-state :title="__('لا توجد مرتجعات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <div class="lg:col-span-2">
            <x-admin.table :title="__('شحنات قيد التوصيل')">
                <thead><tr><th>{{ __('الرقم') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('المستلم') }}</th><th>{{ __('المدينة') }}</th><th>{{ __('التاريخ') }}</th></tr></thead>
                <tbody>
                    @forelse ($data['pending_list'] as $r)
                        <tr><td class="font-mono text-xs">{{ $r->number }}</td><td class="text-xs">{{ $r->status }}</td><td class="text-gray-800">{{ $r->recipient_name }}</td><td class="text-gray-500">{{ $r->city ?? '—' }}</td><td class="text-gray-500 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d') }}</td></tr>
                    @empty <tr><td colspan="5" class="!p-0"><x-admin.empty-state :title="__('لا توجد شحنات معلّقة')" /></td></tr> @endforelse
                </tbody>
            </x-admin.table>
        </div>
    </div>
</x-app-layout>
