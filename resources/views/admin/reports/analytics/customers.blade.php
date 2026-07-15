<x-app-layout :title="__('تقارير العملاء')">
    <x-admin.header
        :title="__('تقارير العملاء')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('العملاء') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('إجمالي العملاء')" :value="number_format($data['total'])" tone="blue" />
        <x-admin.stat-card :label="__('عملاء جدد')" :value="number_format($data['new'])" tone="green" />
        <x-admin.stat-card :label="__('عملاء لديهم مرتجعات')" :value="number_format($data['with_returns']->count())" tone="amber" />
        <x-admin.stat-card :label="__('غير نشِطين (90 يومًا)')" :value="number_format($data['inactive']->count())" tone="red" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-card p-4 lg:col-span-2">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('الأعلى إنفاقًا') }}</h3>
            @if ($data['top']->isNotEmpty())
                <x-admin.chart type="bar" :labels="$data['top']->take(10)->pluck('name')" :datasets="[['label' => __('الإنفاق'), 'data' => $data['top']->take(10)->pluck('total')]]" />
            @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
        </div>
        <div class="admin-card p-4">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('العملاء حسب المدينة') }}</h3>
            @if ($data['by_city']->isNotEmpty())
                <x-admin.chart type="doughnut" :height="260" :labels="$data['by_city']->pluck('name')" :datasets="[['data' => $data['by_city']->pluck('c')]]" />
            @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('الأعلى إنفاقًا (قيمة العميل)')">
            <thead><tr><th>{{ __('العميل') }}</th><th>{{ __('الطلبات') }}</th><th>{{ __('الإجمالي') }}</th></tr></thead>
            <tbody>
                @forelse ($data['top'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ number_format($r->orders_count) }}</td><td class="tabular-nums text-emerald-600">{{ number_format($r->total, 2) }} {{ $cur }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا توجد بيانات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('عملاء لديهم مرتجعات')">
            <thead><tr><th>{{ __('العميل') }}</th><th>{{ __('المرتجعات') }}</th><th>{{ __('الملغاة') }}</th></tr></thead>
            <tbody>
                @forelse ($data['with_returns'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums text-rose-600">{{ $r->returns_count }}</td><td class="tabular-nums">{{ $r->cancelled_orders_count }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا يوجد')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('عملاء غير نشِطين — بحاجة متابعة')">
            <thead><tr><th>{{ __('العميل') }}</th><th>{{ __('الهاتف') }}</th><th>{{ __('مسجَّل منذ') }}</th></tr></thead>
            <tbody>
                @forelse ($data['inactive'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums" dir="ltr">{{ $r->primary_phone }}</td><td class="text-gray-500 text-xs">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d') }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا يوجد')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('أعياد ميلاد اليوم')">
            <thead><tr><th>{{ __('العميل') }}</th><th>{{ __('الميلاد') }}</th><th>{{ __('الهاتف') }}</th></tr></thead>
            <tbody>
                @forelse ($data['birthdays'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="text-gray-500 text-xs">{{ $r->birth_date }}</td><td class="tabular-nums" dir="ltr">{{ $r->primary_phone }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا أعياد ميلاد اليوم')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
