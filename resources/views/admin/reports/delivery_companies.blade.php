<x-report.layout :title="__('reports.delivery_companies')" :range="$range" :searchable="true" :exportable="true">
    <div class="bg-white shadow-sm sm:rounded-lg p-4 overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr>
                <th class="py-2 px-3 font-medium">{{ __('reports.name') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.shipments') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.closed') }}</th>
                <th class="py-2 px-3 font-medium">{{ __('reports.returned') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr class="border-b">
                        <td class="py-2 px-3">{{ $r->name }}</td>
                        <td class="py-2 px-3">{{ $r->shipments }}</td>
                        <td class="py-2 px-3">{{ $r->closed }}</td>
                        <td class="py-2 px-3">{{ $r->returns }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('reports.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-report.layout>
