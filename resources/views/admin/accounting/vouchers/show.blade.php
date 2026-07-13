@php $res = $kind === 'income' ? 'income' : $kind.'s'; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('accounting.kind.'.$kind) }} — {{ $voucher->number }}</h2>
            <a href="{{ route('admin.accounting.vouchers.print', [$kind, $voucher]) }}" target="_blank" class="text-sm text-gray-600 hover:underline">🖨 {{ __('طباعة') }}</a>
        </div>
    </x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <x-accounting.status :status="$voucher->status" />
                <div class="flex flex-wrap gap-2">
                    @if ($voucher->status === 'draft')
                        @can('accounting.'.$res.'.approve')<form method="POST" action="{{ route('admin.accounting.vouchers.approve', [$kind, $voucher]) }}">@csrf<button class="px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-md">{{ __('اعتماد') }}</button></form>
                        <form method="POST" action="{{ route('admin.accounting.vouchers.reject', [$kind, $voucher]) }}">@csrf<button class="px-3 py-1.5 bg-rose-600 text-white text-sm rounded-md">{{ __('رفض') }}</button></form>@endcan
                        @can('accounting.'.$res.'.create')<form method="POST" action="{{ route('admin.accounting.vouchers.cancel', [$kind, $voucher]) }}">@csrf<button class="px-3 py-1.5 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</button></form>@endcan
                    @elseif ($voucher->status === 'approved')
                        @can('accounting.'.$res.'.post')<form method="POST" action="{{ route('admin.accounting.vouchers.post', [$kind, $voucher]) }}">@csrf<button class="px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md">{{ __('ترحيل') }}</button></form>@endcan
                        @can('accounting.'.$res.'.create')<form method="POST" action="{{ route('admin.accounting.vouchers.cancel', [$kind, $voucher]) }}">@csrf<button class="px-3 py-1.5 bg-gray-200 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</button></form>@endcan
                    @elseif ($voucher->status === 'posted')
                        @can('accounting.'.$res.'.post')
                            <form method="POST" action="{{ route('admin.accounting.vouchers.reverse', [$kind, $voucher]) }}" onsubmit="return confirm('{{ __('عكس السند بقيد عاكس؟') }}')">@csrf<button class="px-3 py-1.5 bg-fuchsia-600 text-white text-sm rounded-md">{{ __('عكس') }}</button></form>
                        @endcan
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                <div><dt class="text-gray-500">{{ __('التاريخ') }}</dt><dd>{{ $voucher->voucher_date->format('Y-m-d') }}</dd></div>
                <div><dt class="text-gray-500">{{ __('المبلغ') }}</dt><dd class="font-bold text-lg">{{ number_format($voucher->amount, 2) }} {{ $voucher->currency }}</dd></div>
                <div><dt class="text-gray-500">{{ __('الخزينة') }}</dt><dd>{{ $voucher->treasury?->name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('الحساب المقابل') }}</dt><dd>{{ $voucher->counterAccount?->code }} — {{ $voucher->counterAccount?->name }}</dd></div>
                <div><dt class="text-gray-500">{{ __('الطرف') }}</dt><dd>{{ $voucher->party_name ?: '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('طريقة الدفع') }}</dt><dd>{{ $voucher->payment_method ?: '—' }}</dd></div>
                @if ($voucher->category)<div><dt class="text-gray-500">{{ __('الفئة') }}</dt><dd>{{ $voucher->category }}</dd></div>@endif
                @if ($voucher->reference)<div><dt class="text-gray-500">{{ __('المرجع') }}</dt><dd>{{ $voucher->reference }}</dd></div>@endif
                @if ($voucher->journalEntry)<div><dt class="text-gray-500">{{ __('القيد') }}</dt><dd class="font-mono">{{ $voucher->journalEntry->number }}</dd></div>@endif
            </dl>
            @if ($voucher->description)<p class="mt-4 text-sm text-gray-600">{{ $voucher->description }}</p>@endif
            @if ($voucher->notes)<p class="mt-1 text-xs text-gray-400">{{ $voucher->notes }}</p>@endif

            @if (! empty($voucher->attachments))
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($voucher->attachments as $att)
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($att['path']) }}" target="_blank" class="text-xs text-sky-600 hover:underline">📎 {{ $att['name'] ?? __('مرفق') }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="text-xs text-gray-400 flex flex-wrap gap-4">
            <span>{{ __('أنشأ') }}: {{ $voucher->creator?->name }}</span>
            @if ($voucher->approved_at)<span>{{ __('اعتمد') }}: {{ $voucher->approved_at->format('Y-m-d H:i') }}</span>@endif
            @if ($voucher->posted_at)<span>{{ __('رُحّل') }}: {{ $voucher->posted_at->format('Y-m-d H:i') }}</span>@endif
        </div>
    </div>
</x-app-layout>
