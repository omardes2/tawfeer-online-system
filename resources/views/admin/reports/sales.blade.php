<x-report.layout :title="__('reports.sales_dashboard')" :range="$range">
    <x-report.kpi :items="[
        ['label' => __('reports.sales_total'), 'value' => number_format($data['total'], 2)],
        ['label' => __('reports.orders'), 'value' => $data['count']],
    ]" />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-report.bars :title="__('reports.by_status')" :money="true"
            :rows="collect($data['by_status'])->map(fn($r) => ['label' => $r->status, 'value' => (float) $r->t])->all()" />
        <x-report.bars :title="__('reports.by_channel')" :money="true"
            :rows="collect($data['by_channel'])->map(fn($r) => ['label' => $r->channel, 'value' => (float) $r->t])->all()" />
    </div>

    <x-report.bars :title="__('reports.daily_trend')" :money="true"
        :rows="collect($data['daily'])->map(fn($r) => ['label' => $r->d, 'value' => (float) $r->t])->all()" />
</x-report.layout>
