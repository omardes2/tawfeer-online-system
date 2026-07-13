@props(['status'])
@php
    $map = [
        'draft' => 'bg-slate-100 text-slate-700',
        'new' => 'bg-amber-100 text-amber-700',
        'awaiting_contact' => 'bg-amber-100 text-amber-700',
        'awaiting_confirmation' => 'bg-amber-100 text-amber-700',
        'confirmed' => 'bg-blue-100 text-blue-700',
        'processing' => 'bg-amber-100 text-amber-700',
        'stock_reserved' => 'bg-indigo-100 text-indigo-700',
        'preparing' => 'bg-indigo-100 text-indigo-700',
        'ready_to_ship' => 'bg-violet-100 text-violet-700',
        'shipped' => 'bg-violet-100 text-violet-700',
        'out_for_delivery' => 'bg-violet-100 text-violet-700',
        'delivered' => 'bg-emerald-100 text-emerald-700',
        'delayed' => 'bg-amber-100 text-amber-700',
        'customer_unavailable' => 'bg-amber-100 text-amber-700',
        'cancelled' => 'bg-rose-100 text-rose-700',
        'delivery_failed' => 'bg-rose-100 text-rose-700',
        'returned' => 'bg-slate-200 text-slate-700',
        'partially_returned' => 'bg-slate-200 text-slate-700',
        'exchanged' => 'bg-slate-200 text-slate-700',
    ];
    $labels = [
        'draft' => 'مسودّة', 'new' => 'جديد', 'awaiting_contact' => 'بانتظار التواصل',
        'awaiting_confirmation' => 'بانتظار التأكيد', 'confirmed' => 'مؤكّد', 'processing' => 'قيد المعالجة',
        'stock_reserved' => 'محجوز المخزون', 'preparing' => 'قيد التجهيز',
        'ready_to_ship' => 'جاهز للشحن', 'shipped' => 'مُشحَن', 'out_for_delivery' => 'خرج للتوصيل',
        'delivered' => 'مُسلَّم', 'delayed' => 'مؤجّل', 'customer_unavailable' => 'العميل غير متاح',
        'cancelled' => 'مُلغى', 'delivery_failed' => 'فشل التسليم', 'returned' => 'مُرتجَع',
        'partially_returned' => 'مُرتجَع جزئيًا', 'exchanged' => 'مُستبدَل',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $map[$status] ?? 'bg-slate-100 text-slate-700' }}">{{ __($labels[$status] ?? $status) }}</span>
