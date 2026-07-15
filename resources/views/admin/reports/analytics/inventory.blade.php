<x-app-layout :title="__('تقارير المخزون')">
    <x-admin.header
        :title="__('تقارير المخزون')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('المخزون') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('قيمة المخزون')" :value="$data['total_value']" tone="green" money />
        <x-admin.stat-card :label="__('إجمالي الوحدات')" :value="number_format($data['total_units'])" tone="blue" />
        <x-admin.stat-card :label="__('أصناف بها رصيد')" :value="number_format($data['skus'])" tone="blue" />
        <x-admin.stat-card :label="__('حركة داخلة / خارجة')" :value="number_format($data['incoming']).' / '.number_format($data['outgoing'])" tone="amber" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('حركة المخزون حسب النوع') }}</h3>
            @if ($data['by_type']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260" :labels="$data['by_type']->pluck('type')" :datasets="[['data' => $data['by_type']->pluck('qty')]]" />
            @else <x-admin.empty-state :title="__('لا توجد حركة')" /> @endif
        </div>
        <x-admin.table :title="__('المخزون الراكد (بلا حركة 90 يومًا)')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>{{ __('الرصيد') }}</th><th>{{ __('آخر حركة') }}</th></tr></thead>
            <tbody>
                @forelse ($data['dead_stock'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ $r->on_hand }}</td><td class="text-gray-500 text-xs">{{ $r->last_movement_at ? \Illuminate\Support\Carbon::parse($r->last_movement_at)->format('Y-m-d') : __('—') }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا يوجد مخزون راكد')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('منخفضة المخزون')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>SKU</th><th>{{ __('المتوفّر') }}</th><th>{{ __('حد الطلب') }}</th></tr></thead>
            <tbody>
                @forelse ($data['low_stock'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums text-xs">{{ $r->sku }}</td><td class="tabular-nums text-amber-600">{{ $r->on_hand }}</td><td class="tabular-nums text-gray-400">{{ $r->reorder_level }}</td></tr>
                @empty <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا يوجد نقص')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('آخر حركات المخزون')">
            <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('المنتج') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الكمية') }}</th></tr></thead>
            <tbody>
                @forelse ($data['recent'] as $r)
                    <tr><td class="text-gray-500 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d') }}</td><td class="font-medium text-gray-800">{{ $r->product ?? '—' }}</td><td class="text-xs">{{ $r->type }}</td><td class="tabular-nums {{ $r->qty < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $r->qty }}</td></tr>
                @empty <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا توجد حركة')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
