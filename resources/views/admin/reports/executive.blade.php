<x-report.layout :title="__('reports.executive')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.orders'), 'value' => $data['orders']],
        ['label' => __('reports.sales_total'), 'value' => number_format($data['sales_total'], 2)],
        ['label' => __('reports.delivered'), 'value' => $data['delivered']],
        ['label' => __('reports.commissions'), 'value' => number_format($data['commissions'], 2)],
        ['label' => __('reports.settlements_net'), 'value' => number_format($data['settlements_net'], 2)],
        ['label' => __('reports.returns'), 'value' => $data['returns']],
        ['label' => __('reports.new_customers'), 'value' => $data['new_customers']],
    ]" />
</x-report.layout>
