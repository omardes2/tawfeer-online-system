<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('settlements.settlements') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />

            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div class="flex flex-wrap gap-1">
                    <a href="{{ route('admin.settlements.index') }}"
                       @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => ! $status, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => (bool) $status])>
                        {{ __('settlements.all_statuses') }}
                    </a>
                    @foreach ($statuses as $s)
                        <a href="{{ route('admin.settlements.index', ['status' => $s]) }}"
                           @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => $status === $s, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== $s])>
                            {{ __('settlements.status_label.'.$s) }}
                        </a>
                    @endforeach
                </div>
                @can('settlements.manage')
                    <a href="{{ route('admin.settlements.create') }}" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('settlements.new') }}</a>
                @endcan
            </div>

            @if ($settlements->isEmpty())
                <p class="text-gray-500 text-center py-8">{{ __('settlements.no_settlements') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('settlements.number') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('settlements.provider') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('settlements.net') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('settlements.variance') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('settlements.status') }}</th>
                            <th class="py-2 px-3"></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($settlements as $s)
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-3 font-mono text-xs">{{ $s->number }}</td>
                                    <td class="py-2 px-3">{{ $s->provider?->name ?? '—' }}</td>
                                    <td class="py-2 px-3">{{ number_format((float) $s->computed_net, 2) }}</td>
                                    <td class="py-2 px-3 {{ (float) $s->variance != 0.0 ? 'text-rose-600 font-bold' : 'text-gray-400' }}">{{ number_format((float) $s->variance, 2) }}</td>
                                    <td class="py-2 px-3"><span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 text-xs">{{ __('settlements.status_label.'.$s->status) }}</span></td>
                                    <td class="py-2 px-3"><a href="{{ route('admin.settlements.show', $s) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $settlements->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
