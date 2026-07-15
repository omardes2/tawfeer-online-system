<x-app-layout :title="__('التقارير المالية')">
    <x-admin.header
        :title="__('التقارير المالية')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('التقارير والتحليلات') => route('admin.reports.analytics.index'), __('المالية') => null]" />

    <x-admin.report-filters :range="$range" />

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <x-admin.stat-card :label="__('الإيراد')" :value="$data['revenue']" tone="green" money />
        <x-admin.stat-card :label="__('تكلفة البضاعة')" :value="$data['cogs']" tone="amber" money />
        <x-admin.stat-card :label="__('الربح الإجمالي')" :value="$data['gross_profit']" tone="green" money />
        <x-admin.stat-card :label="__('صافي الربح')" :value="$data['net_profit']" :tone="$data['net_profit'] >= 0 ? 'green' : 'red'" money :hint="__('بعد العمولات والشحن')" />
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-admin.stat-card :label="__('العمولات')" :value="$data['commissions']" tone="amber" money />
        <x-admin.stat-card :label="__('الشحن')" :value="$data['shipping']" tone="amber" money />
        <x-admin.stat-card :label="__('المحصّل')" :value="$data['collected']" tone="green" money />
        <x-admin.stat-card :label="__('مستحقّ على العملاء')" :value="$data['customer_balances']" tone="red" money />
    </div>

    <div class="admin-card p-4 mb-6">
        <h3 class="font-semibold text-gray-800 mb-3">{{ __('الربح اليومي') }}</h3>
        @if ($data['daily_profit']->isNotEmpty())
            <x-admin.chart type="area"
                :labels="$data['daily_profit']->pluck('d')"
                :datasets="[
                    ['label' => __('الإيراد'), 'data' => $data['daily_profit']->pluck('revenue'), 'color' => '#0ea5e9'],
                    ['label' => __('الربح'), 'data' => $data['daily_profit']->pluck('profit'), 'color' => '#10b981'],
                ]" />
        @else <x-admin.empty-state :title="__('لا توجد بيانات')" /> @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-admin.table :title="__('المقبوضات حسب طريقة الدفع')">
            <thead><tr><th>{{ __('الطريقة') }}</th><th>{{ __('العدد') }}</th><th>{{ __('المبلغ') }}</th></tr></thead>
            <tbody>
                @forelse ($data['by_payment_method'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="tabular-nums">{{ $r->c }}</td><td class="tabular-nums text-emerald-600">{{ number_format($r->t, 2) }} {{ $cur }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا توجد مقبوضات')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
        <x-admin.table :title="__('الخزائن والحسابات')">
            <thead><tr><th>{{ __('الاسم') }}</th><th>{{ __('النوع') }}</th><th>{{ __('الرصيد الافتتاحي') }}</th></tr></thead>
            <tbody>
                @forelse ($data['treasuries'] as $r)
                    <tr><td class="font-medium text-gray-800">{{ $r->name }}</td><td class="text-xs">{{ $r->type }}</td><td class="tabular-nums">{{ number_format((float) $r->opening_balance, 2) }} {{ $r->currency }}</td></tr>
                @empty <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('لا توجد خزائن')" /></td></tr> @endforelse
            </tbody>
        </x-admin.table>
    </div>

    <p class="mt-4 text-xs text-gray-400">{{ __('ملاحظة: تقارير المصروفات التفصيلية تتطلّب تفعيل وحدة سندات الصرف/المصروفات (متوفّرة في «المالية والمحاسبة»).') }}</p>
</x-app-layout>
