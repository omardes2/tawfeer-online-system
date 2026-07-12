<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('delivery.engine') }} — {{ $shipment->number }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <a href="{{ route('admin.shipping.delivery.index') }}" class="text-sm text-gray-500 hover:text-indigo-600">← {{ __('delivery.engine') }}</a>

            @if ($errors->any())
                <div class="mt-3 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            {{-- الحالة الحالية: قانونية منفصلة عن المزوّد --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4">
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-lg font-bold text-indigo-700">{{ __('delivery.status.'.$shipment->delivery_status) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('delivery.delivery_status') }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-lg font-bold text-gray-500">{{ $shipment->provider_status ?? '—' }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('delivery.provider_status') }}</p>
                </div>
                @if ($shipment->on_hold_reason)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-bold text-amber-800">{{ __('delivery.reason_label.'.$shipment->on_hold_reason) }}</p>
                        <p class="text-xs text-amber-700 mt-1">{{ __('delivery.on_hold_reason') }}</p>
                    </div>
                @endif
                @if ($shipment->closed_at)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm font-bold text-emerald-800">{{ __('delivery.settled') }}</p>
                        <p class="text-xs text-emerald-700 mt-1">{{ $shipment->closed_at->format('Y-m-d H:i') }}</p>
                    </div>
                @endif
            </div>

            {{-- الانتقالات القانونية المتاحة (close عبر زرّه المستقل) --}}
            @php $manual = array_values(array_filter($allowed, fn ($s) => $s !== 'closed')); @endphp
            @can('shipping.delivery.manage')
                @if (! empty($manual))
                    <form method="POST" action="{{ route('admin.shipping.delivery.transition', $shipment) }}" class="flex flex-wrap items-end gap-3 border-t pt-4">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.move_to') }}</label>
                            <select name="to" class="rounded-md border-gray-300 text-sm">
                                @foreach ($manual as $s)
                                    <option value="{{ $s }}">{{ __('delivery.status.'.$s) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.reason') }}</label>
                            <select name="reason" class="rounded-md border-gray-300 text-sm">
                                <option value="">—</option>
                                @foreach ($reasons as $r)
                                    <option value="{{ $r }}">{{ __('delivery.reason_label.'.$r) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.note') }}</label>
                            <input type="text" name="note" class="rounded-md border-gray-300 text-sm w-48">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('delivery.apply') }}</button>
                    </form>
                @endif
            @endcan

            {{-- الإغلاق المالي (مفصول، مقصور بصلاحية) --}}
            @can('shipping.delivery.close')
                @if (in_array('closed', $allowed, true))
                    <form method="POST" action="{{ route('admin.shipping.delivery.close', $shipment) }}" class="mt-4 border-t pt-4"
                          onsubmit="return confirm('{{ __('delivery.close_confirm') }}')">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('delivery.close') }}</button>
                    </form>
                @endif
            @endcan
        </div>

        {{-- سجلّ الحالة القانونية --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('delivery.canonical_history') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.from') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.to') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.actor_type') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.reason') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.note') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('delivery.at') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($shipment->deliveryTransitions as $t)
                            <tr class="border-b">
                                <td class="py-2 px-3 text-gray-400">{{ $t->from_status ? __('delivery.status.'.$t->from_status) : '—' }}</td>
                                <td class="py-2 px-3">{{ __('delivery.status.'.$t->to_status) }}</td>
                                <td class="py-2 px-3 text-xs">{{ __('delivery.actor.'.$t->actor_type) }}{{ $t->actor ? ' — '.$t->actor->name : '' }}</td>
                                <td class="py-2 px-3 text-xs">{{ $t->reason_code ? __('delivery.reason_label.'.$t->reason_code) : '—' }}</td>
                                <td class="py-2 px-3 text-xs text-gray-500">{{ $t->note ?? '—' }}</td>
                                <td class="py-2 px-3 text-xs text-gray-500">{{ $t->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- سجلّ حالة المزوّد الخام (منفصل) --}}
        @if ($shipment->providerTransitions->isNotEmpty())
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">{{ __('delivery.provider_history') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.from') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.to') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.delivery_status') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('delivery.at') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($shipment->providerTransitions as $t)
                                <tr class="border-b">
                                    <td class="py-2 px-3 text-gray-400 text-xs">{{ $t->from_provider_status ?? '—' }}</td>
                                    <td class="py-2 px-3 text-xs">{{ $t->to_provider_status }}</td>
                                    <td class="py-2 px-3 text-xs">{{ $t->canonical_status ? __('delivery.status.'.$t->canonical_status) : '—' }}</td>
                                    <td class="py-2 px-3 text-xs text-gray-500">{{ $t->received_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
