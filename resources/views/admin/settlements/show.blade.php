<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('settlements.settlements') }} — {{ $settlement->number }}</h2></x-slot>
    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            <a href="{{ route('admin.settlements.index') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('settlements.settlements') }}</a>
            @if ($errors->any())
                <div class="mt-3 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            <div class="flex items-center gap-3 my-3">
                <span class="inline-block px-3 py-1 rounded-md bg-emerald-50 text-emerald-700 text-sm">{{ __('settlements.status_label.'.$settlement->status) }}</span>
                <span class="text-sm text-gray-500">{{ $settlement->provider?->name }}</span>
                @if ($settlement->accounting_entry_id)<span class="text-xs text-emerald-600">{{ __('settlements.accounting_entry') }} #{{ $settlement->accounting_entry_id }}</span>@endif
            </div>

            {{-- المُبلَّغ مقابل المحسوب --}}
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right border">
                    <thead class="text-gray-500 border-b bg-gray-50"><tr>
                        <th class="py-2 px-3"></th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.cod') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.fees') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.deductions') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.net') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr class="border-b"><td class="py-2 px-3 text-gray-500">{{ __('settlements.reported') }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->reported_cod_total, 2) }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->reported_fees_total, 2) }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->reported_deductions_total, 2) }}</td>
                            <td class="py-2 px-3 font-bold">{{ number_format((float) $settlement->reported_net, 2) }}</td>
                        </tr>
                        <tr><td class="py-2 px-3 text-gray-500">{{ __('settlements.computed') }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->computed_cod_total, 2) }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->computed_fees_total, 2) }}</td>
                            <td class="py-2 px-3">{{ number_format((float) $settlement->computed_deductions_total, 2) }}</td>
                            <td class="py-2 px-3 font-bold">{{ number_format((float) $settlement->computed_net, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm {{ (float) $settlement->variance != 0.0 ? 'text-rose-600 font-bold' : 'text-gray-400' }}">
                {{ __('settlements.variance') }}: {{ number_format((float) $settlement->variance, 2) }}
            </p>

            {{-- إجراءات الحالة --}}
            <div class="flex flex-wrap gap-2 mt-4 border-t pt-4">
                @if ($settlement->status === 'draft')
                    @can('settlements.reconcile')
                        <form method="POST" action="{{ route('admin.settlements.reconcile', $settlement) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('settlements.reconcile') }}</button></form>
                    @endcan
                @elseif ($settlement->status === 'reconciled')
                    @can('settlements.post')
                        <form method="POST" action="{{ route('admin.settlements.post', $settlement) }}" onsubmit="return confirm('{{ __('settlements.post_confirm') }}')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('settlements.post') }}</button></form>
                    @endcan
                @elseif ($settlement->status === 'posted')
                    @can('settlements.post')
                        <form method="POST" action="{{ route('admin.settlements.close', $settlement) }}">@csrf<button class="px-4 py-2 bg-gray-700 text-white text-sm rounded-md">{{ __('settlements.close') }}</button></form>
                    @endcan
                @endif
                @if (in_array($settlement->status, ['draft', 'reconciled'], true))
                    @can('settlements.manage')
                        <form method="POST" action="{{ route('admin.settlements.cancel', $settlement) }}">@csrf<button class="px-3 py-2 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('settlements.cancel') }}</button></form>
                    @endcan
                @endif
            </div>
        </div>

        {{-- السطور --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('settlements.lines') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.order') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.cod') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.fees') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.net') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.reported_cod') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('settlements.matched') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($settlement->lines as $line)
                            <tr class="border-b">
                                <td class="py-2 px-3">{{ $line->order?->number ?? '—' }}</td>
                                <td class="py-2 px-3">{{ number_format((float) $line->cod_amount, 2) }}</td>
                                <td class="py-2 px-3">{{ number_format((float) $line->fee_amount, 2) }}</td>
                                <td class="py-2 px-3">{{ number_format((float) $line->net_amount, 2) }}</td>
                                <td class="py-2 px-3">{{ $line->reported_cod !== null ? number_format((float) $line->reported_cod, 2) : '—' }}</td>
                                <td class="py-2 px-3">{{ $line->matched ? '✓' : ((float) $line->variance != 0.0 ? '✗' : '—') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- إضافة شحنات مُغلقة غير مُسوّاة --}}
            @if ($settlement->status === 'draft')
                @can('settlements.manage')
                    <form method="POST" action="{{ route('admin.settlements.shipments', $settlement) }}" class="mt-4 border-t pt-4">
                        @csrf
                        <p class="text-xs text-gray-500 mb-2">{{ __('settlements.add_closed_shipments') }}</p>
                        @forelse ($closedShipments as $shipment)
                            <label class="flex items-center gap-2 text-sm mb-1">
                                <input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" class="rounded border-gray-300">
                                <span class="font-mono text-xs">{{ $shipment->number }}</span>
                                <span class="text-gray-500">{{ $shipment->order?->number }} — {{ number_format((float) $shipment->order?->total, 2) }}</span>
                            </label>
                        @empty
                            <p class="text-gray-400 text-sm">{{ __('settlements.no_closed_shipments') }}</p>
                        @endforelse
                        @if ($closedShipments->isNotEmpty())
                            <button class="mt-2 px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('settlements.add') }}</button>
                        @endif
                    </form>
                @endcan
            @endif
        </div>
    </div>
</x-app-layout>
