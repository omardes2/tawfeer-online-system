<x-report.layout :title="__('reports.returns')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.returns'), 'value' => $data['count']],
        ['label' => __('reports.refund_total'), 'value' => number_format($data['refund_total'], 2)],
    ]" />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-report.bars :title="__('reports.by_reason')"
            :rows="collect($data['by_reason'])->map(fn($r) => ['label' => __('returns.reason_label.'.$r->reason_code), 'value' => (int) $r->c])->all()" />
        <x-report.bars :title="__('reports.by_type')"
            :rows="collect($data['by_type'])->map(fn($r) => ['label' => __('returns.type_label.'.$r->type), 'value' => (int) $r->c])->all()" />
        <x-report.bars :title="__('reports.by_resolution')"
            :rows="collect($data['by_resolution'])->map(fn($r) => ['label' => __('returns.resolution_label.'.$r->resolution), 'value' => (int) $r->c])->all()" />
        <x-report.bars :title="__('reports.by_route')"
            :rows="collect($data['by_route'])->map(fn($r) => ['label' => __('returns.route_label.'.$r->inventory_route), 'value' => (int) $r->c])->all()" />
    </div>
</x-report.layout>
