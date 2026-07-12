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

        {{-- الخطّ الزمني الموحّد --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('delivery.timeline') }}</h3>
            <ol class="relative border-s border-gray-200 space-y-4 ps-4">
                @foreach ($timeline as $item)
                    <li class="text-sm">
                        <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full
                            @class([
                                'bg-indigo-500' => $item['type'] === 'status',
                                'bg-gray-400' => $item['type'] === 'provider_status',
                                'bg-sky-500' => $item['type'] === 'webhook',
                                'bg-amber-500' => $item['type'] === 'sync',
                            ])"></span>
                        @if ($item['type'] === 'status')
                            <span class="font-medium text-indigo-700">{{ __('delivery.timeline_status') }}</span>:
                            {{ $item['from'] ? __('delivery.status.'.$item['from']) : '—' }} → {{ __('delivery.status.'.$item['to']) }}
                            <span class="text-xs text-gray-400">({{ __('delivery.actor.'.$item['actor_type']) }})</span>
                            @if ($item['reason_code'])<span class="text-amber-700 text-xs"> — {{ __('delivery.reason_label.'.$item['reason_code']) }}</span>@endif
                        @elseif ($item['type'] === 'provider_status')
                            <span class="font-medium text-gray-600">{{ __('delivery.timeline_provider') }}</span>:
                            <span class="text-gray-500">{{ $item['from'] ?? '—' }} → {{ $item['to'] }}</span>
                        @else
                            <span class="font-medium {{ $item['type'] === 'webhook' ? 'text-sky-700' : 'text-amber-700' }}">
                                {{ $item['type'] === 'webhook' ? __('delivery.timeline_webhook') : __('delivery.timeline_sync') }}</span>:
                            <span class="text-gray-500">{{ $item['provider_status'] ?? '—' }} ({{ $item['result'] }})</span>
                        @endif
                        <span class="block text-xs text-gray-400">{{ optional($item['at'])->format('Y-m-d H:i') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- الاستثناءات (SLA/تصعيد) --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('delivery.exceptions') }}</h3>
            @forelse ($shipment->exceptions as $ex)
                <div class="border rounded-md p-3 mb-2 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium">{{ $ex->category?->name }}</span>
                            <span class="ms-2 px-2 py-0.5 rounded text-xs bg-gray-100">{{ __('delivery.status_'.$ex->status) }}</span>
                            @if ($ex->assignee)<span class="ms-2 text-xs text-gray-500">{{ __('delivery.assigned_to') }}: {{ $ex->assignee->name }}</span>@endif
                            @if ($ex->sla_due_at)<span class="ms-2 text-xs {{ $ex->sla_due_at->isPast() && $ex->status !== 'resolved' ? 'text-rose-600 font-bold' : 'text-gray-400' }}">{{ __('delivery.sla_due') }}: {{ $ex->sla_due_at->format('Y-m-d H:i') }}</span>@endif
                        </div>
                        @can('shipping.delivery.manage')
                            @if ($ex->status !== 'resolved')
                                <form method="POST" action="{{ route('admin.shipping.delivery.exceptions.resolve', $ex) }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="text" name="resolution" required placeholder="{{ __('delivery.resolution') }}" class="rounded-md border-gray-300 text-xs w-40">
                                    <button class="px-2 py-1 bg-emerald-600 text-white text-xs rounded">{{ __('delivery.resolve') }}</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </div>
            @empty
                <p class="text-gray-400 text-sm mb-3">{{ __('delivery.no_exceptions') }}</p>
            @endforelse

            @can('shipping.delivery.manage')
                <form method="POST" action="{{ route('admin.shipping.delivery.exceptions.open', $shipment) }}" class="flex flex-wrap items-end gap-2 border-t pt-3 mt-2">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.category') }}</label>
                        <select name="category" class="rounded-md border-gray-300 text-sm">
                            @foreach ($categories as $c)
                                <option value="{{ $c->code }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.note') }}</label>
                        <input type="text" name="note" class="rounded-md border-gray-300 text-sm w-48">
                    </div>
                    <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('delivery.open_exception') }}</button>
                </form>
            @endcan
        </div>

        {{-- الرسوم المُركّبة (مستقلّة عن المزوّد) --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">{{ __('delivery.fees') }}</h3>
                <span class="text-sm font-bold text-gray-900">{{ __('delivery.fee_total') }}: {{ number_format($feeTotal, 2) }}</span>
            </div>
            @forelse ($shipment->feeComponents as $fee)
                <div class="flex items-center justify-between border-b py-1.5 text-sm">
                    <span>{{ __('delivery.fee_type_label.'.$fee->type) }} <span class="text-xs text-gray-400">({{ __('delivery.owner_label.'.$fee->owner) }})</span></span>
                    <span class="{{ (float) $fee->amount < 0 ? 'text-rose-600' : 'text-gray-800' }}">{{ number_format((float) $fee->amount, 2) }}</span>
                </div>
            @empty
                <p class="text-gray-400 text-sm mb-3">{{ __('delivery.no_fees') }}</p>
            @endforelse

            @can('shipping.delivery.fees')
                <form method="POST" action="{{ route('admin.shipping.delivery.fees.add', $shipment) }}" class="flex flex-wrap items-end gap-2 border-t pt-3 mt-2">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.fee_type') }}</label>
                        <select name="type" class="rounded-md border-gray-300 text-sm">
                            @foreach ($feeTypes as $t)
                                <option value="{{ $t }}">{{ __('delivery.fee_type_label.'.$t) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('delivery.fee_amount') }}</label>
                        <input type="number" step="0.01" name="amount" required class="rounded-md border-gray-300 text-sm w-28">
                    </div>
                    <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('delivery.add_fee') }}</button>
                </form>
            @endcan
        </div>
    </div>
</x-app-layout>
