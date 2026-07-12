<x-report.layout :title="__('reports.kpis')" :range="$range">
    {{-- المبيعات والتحصيل والربح --}}
    <h3 class="font-semibold text-gray-700">{{ __('reports.kpi_sales') }}</h3>
    <x-report.kpi :items="[
        ['label' => __('reports.sales_total'), 'value' => number_format($kpis['sales']['total'], 2)],
        ['label' => __('reports.collected'), 'value' => number_format($kpis['sales']['collected'], 2)],
        ['label' => __('reports.unsettled'), 'value' => number_format($kpis['sales']['unsettled'], 2)],
        ['label' => __('reports.gross_profit'), 'value' => number_format($kpis['sales']['gross_profit'], 2)],
        ['label' => __('reports.orders'), 'value' => $kpis['sales']['orders']],
        ['label' => __('reports.avg_order_value'), 'value' => number_format($kpis['sales']['avg_order_value'], 2)],
        ['label' => __('reports.commissions'), 'value' => number_format($kpis['commissions_eligible'], 2)],
        ['label' => __('reports.abandoned_carts'), 'value' => $kpis['abandoned_carts']],
    ]" />

    {{-- التوصيل والمرتجعات --}}
    <h3 class="font-semibold text-gray-700">{{ __('reports.kpi_operations') }}</h3>
    <x-report.kpi :items="[
        ['label' => __('reports.delivery_success_rate'), 'value' => $kpis['delivery']['success_rate'].'%'],
        ['label' => __('reports.delivery_exception_rate'), 'value' => $kpis['delivery']['exception_rate'].'%'],
        ['label' => __('reports.shipments'), 'value' => $kpis['delivery']['shipments']],
        ['label' => __('reports.return_rate'), 'value' => $kpis['returns']['rate'].'%'],
    ]" />

    {{-- التسويق والتوصيات والذكاء الاصطناعي --}}
    <h3 class="font-semibold text-gray-700">{{ __('reports.kpi_growth') }}</h3>
    <x-report.kpi :items="[
        ['label' => __('reports.campaign_sent'), 'value' => $kpis['campaigns']['sent']],
        ['label' => __('reports.campaign_failed'), 'value' => $kpis['campaigns']['failed']],
        ['label' => __('reports.reco_impressions'), 'value' => $kpis['recommendations']['impressions']],
        ['label' => __('reports.reco_clicks'), 'value' => $kpis['recommendations']['clicks']],
        ['label' => __('reports.reco_conversions'), 'value' => $kpis['recommendations']['conversions']],
        ['label' => __('reports.reco_ctr'), 'value' => $kpis['recommendations']['ctr'].'%'],
        ['label' => __('reports.ai_requests'), 'value' => $kpis['ai']['requests']],
        ['label' => __('reports.ai_tokens'), 'value' => number_format($kpis['ai']['tokens'])],
    ]" />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- المبيعات حسب القناة --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('reports.sales_by_channel') }}</h3>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($kpis['sales']['by_channel'] as $row)
                        <tr class="border-b last:border-0"><td class="py-1.5">{{ $row->channel ?? '—' }}</td><td class="py-1.5 text-end">{{ $row->c }}</td><td class="py-1.5 text-end">{{ number_format($row->t, 2) }}</td></tr>
                    @empty
                        <tr><td class="py-2 text-gray-400">{{ __('reports.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- أصناف منخفضة المخزون --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('reports.low_stock') }}</h3>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($kpis['low_stock'] as $row)
                        <tr class="border-b last:border-0"><td class="py-1.5">{{ $row->name }}</td><td class="py-1.5 text-end text-rose-600">{{ rtrim(rtrim(number_format($row->on_hand, 2), '0'), '.') }}</td></tr>
                    @empty
                        <tr><td class="py-2 text-gray-400">{{ __('reports.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- الأعلى مبيعًا --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('reports.top_products') }}</h3>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($kpis['top_products'] as $row)
                        <tr class="border-b last:border-0"><td class="py-1.5">{{ $row->name }}</td><td class="py-1.5 text-end">{{ number_format($row->revenue, 2) }}</td></tr>
                    @empty
                        <tr><td class="py-2 text-gray-400">{{ __('reports.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- أداء موظفي المبيعات --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('reports.sales_employees') }}</h3>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($kpis['employees'] as $row)
                        <tr class="border-b last:border-0"><td class="py-1.5">{{ $row->name }}</td><td class="py-1.5 text-end">{{ number_format($row->sales_total, 2) }}</td><td class="py-1.5 text-end text-gray-500">{{ number_format($row->commissions, 2) }}</td></tr>
                    @empty
                        <tr><td class="py-2 text-gray-400">{{ __('reports.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-report.layout>
