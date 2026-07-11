@props(['status'])
@php
    $map = [
        'draft' => 'bg-slate-100 text-slate-700',
        'pending_approval' => 'bg-amber-100 text-amber-700',
        'approved' => 'bg-blue-100 text-blue-700',
        'partially_received' => 'bg-indigo-100 text-indigo-700',
        'received' => 'bg-emerald-100 text-emerald-700',
        'posted' => 'bg-emerald-100 text-emerald-700',
        'closed' => 'bg-gray-200 text-gray-600',
        'cancelled' => 'bg-rose-100 text-rose-700',
    ];
    $labels = [
        'draft' => 'مسودّة',
        'pending_approval' => 'بانتظار الاعتماد',
        'approved' => 'معتمد',
        'partially_received' => 'مستلم جزئيًا',
        'received' => 'مستلم',
        'posted' => 'مُرحّل',
        'closed' => 'مغلق',
        'cancelled' => 'ملغى',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$status] ?? 'bg-slate-100 text-slate-700' }}">{{ __($labels[$status] ?? $status) }}</span>
