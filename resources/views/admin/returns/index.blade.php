<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('returns.rma') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />

            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div class="flex flex-wrap gap-1">
                    <a href="{{ route('admin.returns.index') }}"
                       @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => ! $status, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => (bool) $status])>
                        {{ __('returns.all_statuses') }}
                    </a>
                    @foreach ($statuses as $s)
                        <a href="{{ route('admin.returns.index', ['status' => $s]) }}"
                           @class(['px-3 py-1.5 rounded-md text-sm', 'bg-indigo-600 text-white' => $status === $s, 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== $s])>
                            {{ __('returns.status_label.'.$s) }}
                        </a>
                    @endforeach
                </div>
                @can('returns.create')
                    <a href="{{ route('admin.returns.create') }}" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('returns.new') }}</a>
                @endcan
            </div>

            @if ($returns->isEmpty())
                <p class="text-gray-500 text-center py-8">{{ __('returns.no_returns') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('returns.number') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.order') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.type') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.reason') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.status') }}</th>
                            <th class="py-2 px-3"></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($returns as $r)
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-3 font-mono text-xs">{{ $r->number }}</td>
                                    <td class="py-2 px-3">{{ $r->order?->number }}</td>
                                    <td class="py-2 px-3">{{ __('returns.type_label.'.$r->type) }}</td>
                                    <td class="py-2 px-3 text-xs">{{ __('returns.reason_label.'.$r->reason_code) }}</td>
                                    <td class="py-2 px-3"><span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 text-xs">{{ __('returns.status_label.'.$r->status) }}</span></td>
                                    <td class="py-2 px-3"><a href="{{ route('admin.returns.show', $r) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $returns->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
