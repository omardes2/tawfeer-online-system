@props(['status'])
@php
    $map = [
        'draft' => 'bg-slate-100 text-slate-600',
        'approved' => 'bg-amber-100 text-amber-700',
        'posted' => 'bg-emerald-100 text-emerald-700',
        'rejected' => 'bg-rose-100 text-rose-700',
        'cancelled' => 'bg-gray-100 text-gray-500',
        'reversed' => 'bg-fuchsia-100 text-fuchsia-700',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$status] ?? 'bg-gray-100 text-gray-600' }}">{{ __('accounting.status.'.$status) }}</span>
