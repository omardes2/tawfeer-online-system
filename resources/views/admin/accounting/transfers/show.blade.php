<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('تحويل') }} — {{ $transfer->number }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <x-accounting.status :status="$transfer->status" />
                <div class="flex flex-wrap gap-2">
                    @if ($transfer->status === 'draft')
                        @can('accounting.transfers.approve')<form method="POST" action="{{ route('admin.accounting.transfers.approve', $transfer) }}">@csrf<button class="px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-md">{{ __('اعتماد') }}</button></form>@endcan
                        @can('accounting.transfers.create')<form method="POST" action="{{ route('admin.accounting.transfers.cancel', $transfer) }}">@csrf<button class="px-3 py-1.5 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</button></form>@endcan
                    @elseif ($transfer->status === 'approved')
                        @can('accounting.transfers.post')<form method="POST" action="{{ route('admin.accounting.transfers.post', $transfer) }}">@csrf<button class="px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md">{{ __('ترحيل') }}</button></form>@endcan
                    @elseif ($transfer->status === 'posted')
                        @can('accounting.transfers.post')<form method="POST" action="{{ route('admin.accounting.transfers.reverse', $transfer) }}" onsubmit="return confirm('{{ __('عكس التحويل؟') }}')">@csrf<button class="px-3 py-1.5 bg-fuchsia-600 text-white text-sm rounded-md">{{ __('عكس') }}</button></form>@endcan
                    @endif
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-gray-500">{{ __('التاريخ') }}</dt><dd>{{ $transfer->voucher_date->format('Y-m-d') }}</dd></div>
                <div><dt class="text-gray-500">{{ __('المبلغ') }}</dt><dd class="font-bold text-lg">{{ number_format($transfer->amount, 2) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('من') }}</dt><dd>{{ $transfer->treasury?->name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('إلى') }}</dt><dd>{{ $transfer->counterTreasury?->name }}</dd></div>
                @if ($transfer->journalEntry)<div><dt class="text-gray-500">{{ __('القيد') }}</dt><dd class="font-mono">{{ $transfer->journalEntry->number }}</dd></div>@endif
            </dl>
        </div>
    </div>
</x-app-layout>
