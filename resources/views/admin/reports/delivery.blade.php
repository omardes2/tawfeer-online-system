<x-report.layout :title="__('reports.delivery_dashboard')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.closed_shipments'), 'value' => $data['closed']],
        ['label' => __('reports.open_exceptions'), 'value' => $data['exceptions_open']],
    ]" />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-report.bars :title="__('reports.by_status')"
            :rows="collect($data['by_status'])->map(fn($r) => ['label' => __('delivery.status.'.$r->delivery_status), 'value' => (int) $r->c])->all()" />
        <x-report.bars :title="__('reports.by_provider')"
            :rows="collect($data['by_provider'])->map(fn($r) => ['label' => $r->name, 'value' => (int) $r->c])->all()" />
    </div>

    <x-report.bars :title="__('reports.hold_reasons')"
        :rows="collect($data['hold_reasons'])->map(fn($r) => ['label' => __('delivery.reason_label.'.$r->reason_code), 'value' => (int) $r->c])->all()" />
</x-report.layout>
