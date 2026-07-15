<x-app-layout :title="__('تقارير المنتجات')">
    <x-admin.header
        :title="__('تقارير المنتجات')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('المنتجات') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('منتجات غير مباعة')" :value="number_format($data['unsold']->count())" tone="gray" />
        <x-admin.stat-card :label="__('منخفضة المخزون')" :value="number_format($data['low_stock']->count())" tone="amber" />
        <x-admin.stat-card :label="__('نفدت من المخزون')" :value="number_format($data['out_of_stock']->count())" tone="red" />
        <x-admin.stat-card :label="__('أعلى ربحية')" :value="$data['profitability']->first()->name ?? '—'" tone="green" />
    </div>

    <div class="admin-card p-4 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">{{ __('الأكثر مبيعًا (بالكمية)') }}</h3>
        @if ($data['best']->isNotEmpty())
            <x-admin.chart type="bar" :labels="$data['best']->take(10)->pluck('name')" :datasets="[['label' => __('الكمية'), 'data' => $data['best']->take(10)->pluck('qty')]]" />
        @else <x-admin.empty-state :title="__('لا توجد مبيعات')" /> @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('الأكثر مبيعًا')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>{{ __('الكمية') }}</th><th>{{ __('الإيراد') }}</th><th>{{ __('الربح') }}</th></tr></thead>
            <tbody>
                @forelse ($data['best'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ number_format($r->qty) }}</td><td class="tabular-nums">{{ number_format($r->revenue, 2) }} {{ $cur }}</td><td class="tabular-nums text-emerald-600">{{ number_format($r->profit, 2) }} {{ $cur }}</td></tr>
                @empty <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا توجد مبيعات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('الأعلى ربحية')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>{{ __('الربح') }}</th><th>{{ __('الإيراد') }}</th></tr></thead>
            <tbody>
                @forelse ($data['profitability'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums text-emerald-600">{{ number_format($r->profit, 2) }} {{ $cur }}</td><td class="tabular-nums">{{ number_format($r->revenue, 2) }} {{ $cur }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا توجد بيانات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('منتجات غير مباعة في الفترة')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>SKU</th><th>{{ __('السعر') }}</th></tr></thead>
            <tbody>
                @forelse ($data['unsold'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums text-xs">{{ $r->sku }}</td><td class="tabular-nums">{{ number_format((float) $r->retail_price, 2) }} {{ $cur }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('كل المنتجات بيعت')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('منخفضة / نافدة المخزون')">
            <thead><tr><th>{{ __('المنتج') }}</th><th>SKU</th><th>{{ __('المتوفّر') }}</th><th>{{ __('حد الطلب') }}</th></tr></thead>
            <tbody>
                @forelse ($data['low_stock']->concat($data['out_of_stock'])->take(30) as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums text-xs">{{ $r->sku }}</td><td class="tabular-nums {{ $r->on_hand <= 0 ? 'text-rose-600' : 'text-amber-600' }}">{{ $r->on_hand }}</td><td class="tabular-nums text-gray-400">{{ $r->reorder_level }}</td></tr>
                @empty <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('المخزون بحالة جيّدة')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
