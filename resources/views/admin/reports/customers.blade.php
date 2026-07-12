<x-report.layout :title="__('reports.customers')" :range="$range" :exportable="true">
    <x-report.kpi :items="[
        ['label' => __('reports.new_customers'), 'value' => $data['new_customers']],
        ['label' => __('reports.repeat_customers'), 'value' => $data['repeat_customers']],
    ]" />

    <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
        <h3 class="font-semibold text-gray-800 mb-3 text-sm">{{ __('reports.top_customers') }}</h3>
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr>
                <th class="py-2 px-3 font-medium">{{ __('reports.name') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.orders_count') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.total') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($data['top'] as $r)
                    <tr class="border-b">
                        <td class="py-2 px-3">{{ $r->name }}</td>
                        <td class="py-2 px-3">{{ $r->orders_count }}</td>
                        <td class="py-2 px-3">{{ number_format((float) $r->total, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">{{ __('reports.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-report.layout>
