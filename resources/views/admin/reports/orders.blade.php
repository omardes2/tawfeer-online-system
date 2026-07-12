<x-report.layout :title="__('reports.orders_dashboard')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.orders'), 'value' => $data['count']],
        ['label' => __('reports.avg_order'), 'value' => number_format($data['avg_value'], 2)],
    ]" />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-report.bars :title="__('reports.by_status')"
            :rows="collect($data['by_status'])->map(fn($r) => ['label' => $r->status, 'value' => (int) $r->c])->all()" />
        <x-report.bars :title="__('reports.by_channel')"
            :rows="collect($data['by_channel'])->map(fn($r) => ['label' => $r->channel, 'value' => (int) $r->c])->all()" />
    </div>
</x-report.layout>
