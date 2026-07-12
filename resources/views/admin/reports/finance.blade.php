<x-report.layout :title="__('reports.finance_dashboard')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.cod_collected'), 'value' => number_format($data['cod_collected'], 2)],
        ['label' => __('reports.fees'), 'value' => number_format($data['fees'], 2)],
        ['label' => __('reports.net'), 'value' => number_format($data['net'], 2)],
        ['label' => __('reports.settlements'), 'value' => $data['settlements']],
        ['label' => __('reports.refunds'), 'value' => number_format($data['refunds'], 2)],
        ['label' => __('reports.eligible_commissions'), 'value' => number_format($data['commission_eligible'], 2)],
    ]" />
</x-report.layout>
