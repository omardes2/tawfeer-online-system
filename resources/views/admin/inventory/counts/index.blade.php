<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('warehouse.counts') }}</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            <div class="flex justify-end mb-4">
                @can('inventory.counts.manage')
                    <form method="POST" action="{{ route('admin.inventory.counts.store') }}">
                        @csrf
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('warehouse.new_count') }}</button>
                    </form>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('warehouse.count_number') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('warehouse.status') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('warehouse.adjusted_items') }}</th>
                        <th class="py-2 px-3"></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($counts as $c)
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-3 font-mono text-xs">{{ $c->number }}</td>
                                <td class="py-2 px-3"><span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 text-xs">{{ __('warehouse.status_label.'.$c->status) }}</span></td>
                                <td class="py-2 px-3">{{ $c->adjusted_count }}</td>
                                <td class="py-2 px-3"><a href="{{ route('admin.inventory.counts.show', $c) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('warehouse.no_counts') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $counts->links() }}</div>
        </div>
    </div>
</x-app-layout>
