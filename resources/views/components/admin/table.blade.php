{{--
    Data-table card wrapper — rounded card, optional toolbar (search/filters via
    the `toolbar` slot), horizontal scroll on small screens, consistent header/
    row styling (via .admin-table). Put <thead>/<tbody> in the default slot.

    Props: title(optional), dense(bool — حشو أفقي مُخفَّض لجداول الأرقام الكثيرة
           الأعمدة، تفاديًا للسحب الأفقي)، stack(bool) — على الجوّال يتحوّل الجدول إلى بطاقات بدل
           السحب الأفقي؛ يتطلّب `data-label` على خلايا الصف لتظهر عناوين الحقول.
    Slots: toolbar (filters row), default (the table), footer (pagination).
--}}
@props(['title' => null, 'stack' => false, 'dense' => false])

<div class="admin-card overflow-hidden">
    @if ($title || isset($toolbar))
        <div class="flex items-center justify-between gap-3 flex-wrap p-4 border-b border-gray-100">
            @if ($title)<h3 class="font-semibold text-gray-800">{{ $title }}</h3>@endif
            @isset($toolbar)<div class="flex items-center gap-2 flex-wrap">{{ $toolbar }}</div>@endisset
        </div>
    @endif

    <div @class(['overflow-x-auto' => ! $stack, 'md:overflow-x-auto' => $stack])>
        <table @class(['admin-table', 'admin-table-stack' => $stack, 'admin-table--dense' => $dense])>
            {{ $slot }}
        </table>
    </div>

    @isset($footer)
        <div class="p-4 border-t border-gray-100">{{ $footer }}</div>
    @endisset
</div>
