@props(['status'])
@php
    $map = [
        'not_shipped' => 'bg-slate-100 text-slate-700',
        'preparing' => 'bg-indigo-100 text-indigo-700',
        'in_transit' => 'bg-blue-100 text-blue-700',
        'out_for_delivery' => 'bg-violet-100 text-violet-700',
        'delayed' => 'bg-amber-100 text-amber-700',
        'customer_unavailable' => 'bg-amber-100 text-amber-700',
        'delivered' => 'bg-emerald-100 text-emerald-700',
        'failed' => 'bg-rose-100 text-rose-700',
    ];
    $labels = [
        'not_shipped' => 'لم يُشحَن', 'preparing' => 'قيد التجهيز', 'in_transit' => 'في الطريق',
        'out_for_delivery' => 'خرج للتوصيل', 'delayed' => 'مؤجّل', 'customer_unavailable' => 'العميل غير متاح',
        'delivered' => 'تم التسليم', 'failed' => 'فشل التسليم',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$status] ?? 'bg-slate-100 text-slate-700' }}">{{ __($labels[$status] ?? $status) }}</span>
