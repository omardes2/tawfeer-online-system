<x-report.layout :title="__('reports.products')" :range="$range" :searchable="true" :exportable="true">
    <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr>
                <th class="py-2 px-3 font-medium">{{ __('reports.name') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.qty') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.revenue') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.returned') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr class="border-b">
                        <td class="py-2 px-3">{{ $r->name }}</td>
                        <td class="py-2 px-3">{{ (float) $r->qty }}</td>
                        <td class="py-2 px-3">{{ number_format((float) $r->revenue, 2) }}</td>
                        <td class="py-2 px-3">{{ (float) $r->returned }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('reports.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-report.layout>
