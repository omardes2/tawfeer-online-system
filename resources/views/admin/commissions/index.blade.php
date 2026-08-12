<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('commissions.title') }}</h2></x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        {{-- فلتر الفترة + روابط ثانوية --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <form method="GET" class="bg-white border border-gray-200 rounded-lg p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_from') }}</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_to') }}</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">{{ __('commissions.apply_filter') }}</button>
            </form>
            <div class="flex gap-4 text-sm">
                <a href="{{ route('admin.commissions.ledger') }}" class="text-gray-500 hover:text-emerald-600">{{ __('commissions.ledger_title') }}</a>
                @can('commissions.rules.manage')
                    <a href="{{ route('admin.commissions.rules') }}" class="text-emerald-600 hover:underline">{{ __('commissions.rules') }}</a>
                @endcan
            </div>
        </div>

        @foreach ([['title' => __('commissions.sales_people'), 'rows' => $sales, 'type' => 'sales'],
                   ['title' => __('commissions.affiliate_people'), 'rows' => $affiliates, 'type' => 'affiliate']] as $group)
            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-semibold text-gray-800 mb-4">{{ $group['title'] }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right admin-table-stack">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.earner') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.period_earned') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.earned') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.total_paid') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.pending_payout') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('commissions.outstanding') }}</th>
                            <th class="py-2 px-3"></th>
                        </tr></thead>
                        <tbody>
                            @forelse ($group['rows'] as $row)
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2.5 px-3 font-medium text-gray-800" data-label="{{ __('commissions.earner') }}">
                                        <a href="{{ route('admin.commissions.statement', ['earnerId' => $row['user']->id, 'earner_type' => $group['type'], 'from' => $from, 'to' => $to]) }}"
                                           class="text-emerald-700 hover:underline">{{ $row['user']->name }}</a>
                                    </td>
                                    <td class="py-2.5 px-3 tabular-nums" data-label="{{ __('commissions.period_earned') }}">{{ number_format($row['period'], 2) }}</td>
                                    <td class="py-2.5 px-3 tabular-nums" data-label="{{ __('commissions.earned') }}">{{ number_format($row['earned'], 2) }}</td>
                                    <td class="py-2.5 px-3 tabular-nums text-emerald-700" data-label="{{ __('commissions.total_paid') }}">{{ number_format($row['paid'], 2) }}</td>
                                    <td class="py-2.5 px-3 tabular-nums text-amber-600" data-label="{{ __('commissions.pending_payout') }}">{{ number_format($row['pending_payout'], 2) }}</td>
                                    <td class="py-2.5 px-3 tabular-nums font-bold {{ $row['outstanding'] < 0 ? 'text-rose-600' : 'text-indigo-700' }}" data-label="{{ __('commissions.outstanding') }}">{{ number_format($row['outstanding'], 2) }}</td>
                                    <td class="py-2.5 px-3 text-end">
                                        <a href="{{ route('admin.commissions.statement', ['earnerId' => $row['user']->id, 'earner_type' => $group['type'], 'from' => $from, 'to' => $to]) }}"
                                           class="inline-flex items-center px-3 py-1.5 rounded-md bg-emerald-600 text-white text-xs hover:bg-emerald-700">{{ __('commissions.open_file') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-6 text-center text-gray-400" data-label="">{{ __('commissions.no_people') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>
