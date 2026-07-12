@props(['status'])
@php
    $map = [
        'pending' => 'bg-amber-100 text-amber-700',
        'processing' => 'bg-blue-100 text-blue-700',
        'paid' => 'bg-emerald-100 text-emerald-700',
        'failed' => 'bg-rose-100 text-rose-700',
        'cancelled' => 'bg-slate-100 text-slate-600',
        'refunded' => 'bg-slate-200 text-slate-700',
        'partially_refunded' => 'bg-amber-100 text-amber-700',
        // order payment_status
        'unpaid' => 'bg-rose-100 text-rose-700',
        'partially_paid' => 'bg-amber-100 text-amber-700',
    ];
    $labels = [
        'pending' => 'قيد الانتظار', 'processing' => 'قيد المعالجة', 'paid' => 'مدفوع',
        'failed' => 'فشل', 'cancelled' => 'ملغى', 'refunded' => 'مُسترَد',
        'partially_refunded' => 'مُسترَد جزئيًا', 'unpaid' => 'غير مدفوع', 'partially_paid' => 'مدفوع جزئيًا',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$status] ?? 'bg-slate-100 text-slate-700' }}">{{ __($labels[$status] ?? $status) }}</span>
