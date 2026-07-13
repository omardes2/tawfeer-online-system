<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('returns.rma') }} — {{ $return->number }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <x-admin.flash />
            <a href="{{ route('admin.returns.index') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('returns.rma') }}</a>
            @if ($errors->any())
                <div class="mt-3 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">{{ $errors->first() }}</div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4">
                <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ __('returns.status') }}</p><p class="font-bold text-emerald-700">{{ __('returns.status_label.'.$return->status) }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ __('returns.type') }}</p><p class="font-medium">{{ __('returns.type_label.'.$return->type) }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ __('returns.resolution') }}</p><p class="font-medium">{{ __('returns.resolution_label.'.$return->resolution) }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ __('returns.order') }}</p><p class="font-mono text-sm">{{ $return->order?->number }}</p></div>
            </div>
            <p class="text-sm text-gray-600">{{ __('returns.reason') }}: {{ __('returns.reason_label.'.$return->reason_code) }}
                @if ($return->linked_shipment_id) · {{ __('returns.linked_shipment') }}: <span class="font-mono">{{ $return->linkedShipment?->number }}</span>@endif
            </p>

            {{-- إجراءات المراحل --}}
            <div class="flex flex-wrap gap-2 mt-4 border-t pt-4">
                @if ($return->status === 'return_request')
                    @can('returns.approve')
                        <form method="POST" action="{{ route('admin.returns.approve', $return) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('returns.approve') }}</button></form>
                        <form method="POST" action="{{ route('admin.returns.reject', $return) }}" class="flex items-center gap-1">@csrf
                            <input type="text" name="reason" required placeholder="{{ __('returns.reject_reason') }}" class="rounded-md border-gray-300 text-xs w-40">
                            <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-md">{{ __('returns.reject') }}</button>
                        </form>
                    @endcan
                @elseif ($return->status === 'approved')
                    @can('returns.receive')
                        <form method="POST" action="{{ route('admin.returns.receive', $return) }}" class="flex items-center gap-2">@csrf
                            <label class="text-xs text-gray-500 flex items-center gap-1"><input type="checkbox" name="create_linked_shipment" value="1" class="rounded border-gray-300">{{ __('returns.create_linked_shipment') }}</label>
                            <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('returns.receive') }}</button>
                        </form>
                    @endcan
                @elseif ($return->status === 'inspected')
                    @can('returns.finalize')
                        <form method="POST" action="{{ route('admin.returns.complete', $return) }}" onsubmit="return confirm('{{ __('returns.complete') }}?')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('returns.complete') }}</button></form>
                    @endcan
                @endif
                @if (in_array($return->status, ['return_request', 'approved', 'received'], true))
                    @can('returns.create')
                        <form method="POST" action="{{ route('admin.returns.cancel', $return) }}">@csrf<button class="px-3 py-2 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('returns.cancel') }}</button></form>
                    @endcan
                @endif
            </div>
        </div>

        {{-- البنود + الفحص --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('returns.items') }}</h3>
            <form method="POST" action="{{ route('admin.returns.inspect', $return) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-right">
                        <thead class="text-gray-500 border-b"><tr>
                            <th class="py-2 px-3 font-medium">{{ __('returns.variant') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.qty') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.inspection_result') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('returns.inventory_route') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($return->items as $i => $item)
                                <tr class="border-b">
                                    <td class="py-2 px-3">{{ $item->variant?->product?->name_ar ?? $item->variant?->sku }}</td>
                                    <td class="py-2 px-3">{{ (float) $item->qty }}</td>
                                    <td class="py-2 px-3">
                                        @if ($return->status === 'received')
                                            <input type="hidden" name="inspections[{{ $i }}][item_id]" value="{{ $item->id }}">
                                            <select name="inspections[{{ $i }}][inspection_result]" class="rounded-md border-gray-300 text-xs">
                                                @foreach ($results as $r)<option value="{{ $r }}">{{ __('returns.result_label.'.$r) }}</option>@endforeach
                                            </select>
                                        @else
                                            {{ $item->inspection_result ? __('returns.result_label.'.$item->inspection_result) : '—' }}
                                        @endif
                                    </td>
                                    <td class="py-2 px-3">
                                        @if ($return->status === 'received')
                                            <select name="inspections[{{ $i }}][inventory_route]" class="rounded-md border-gray-300 text-xs">
                                                @foreach ($routes as $r)<option value="{{ $r }}">{{ __('returns.route_label.'.$r) }}</option>@endforeach
                                            </select>
                                        @else
                                            {{ $item->inventory_route ? __('returns.route_label.'.$item->inventory_route) : '—' }}
                                            @if ($item->restocked) <span class="text-emerald-600 text-xs">✓</span>@endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($return->status === 'received')
                    @can('returns.inspect')
                        <button class="mt-3 px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('returns.apply_inspection') }}</button>
                    @endcan
                @endif
            </form>
        </div>

        {{-- الصور --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('returns.photos') }}</h3>
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($return->photos as $photo)
                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo->path) }}" target="_blank" class="block">
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo->path) }}" class="h-20 w-20 object-cover rounded border">
                    </a>
                @endforeach
            </div>
            @can('returns.create')
                <form method="POST" action="{{ route('admin.returns.photo', $return) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                    @csrf
                    <input type="file" name="photo" accept="image/*" class="text-sm">
                    <button class="px-3 py-2 bg-gray-700 text-white text-sm rounded-md">{{ __('returns.add_photo') }}</button>
                </form>
            @endcan
        </div>

        {{-- الخطّ الزمني --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('returns.timeline') }}</h3>
            <ol class="relative border-s border-gray-200 space-y-3 ps-4">
                @foreach ($return->events as $e)
                    <li class="text-sm">
                        <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full bg-emerald-500"></span>
                        {{ $e->from_status ? __('returns.status_label.'.$e->from_status) : '—' }} → {{ __('returns.status_label.'.$e->to_status) }}
                        <span class="text-xs text-gray-400">({{ $e->stage }}{{ $e->actor ? ' — '.$e->actor->name : '' }})</span>
                        @if ($e->note)<span class="text-xs text-gray-500"> — {{ $e->note }}</span>@endif
                        <span class="block text-xs text-gray-400">{{ optional($e->created_at)->format('Y-m-d H:i') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</x-app-layout>
