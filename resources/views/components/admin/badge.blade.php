{{--
    Status badge with semantic tone + icon (never color-only).
    Props: tone(green|amber|blue|red|gray), label(slot or prop), icon(bool).
--}}
@props(['tone' => 'gray', 'label' => null, 'icon' => true])

@php
    $cls = [
        'green' => 'badge-green', 'amber' => 'badge-amber', 'blue' => 'badge-blue',
        'red' => 'badge-red', 'gray' => 'badge-gray',
    ][$tone] ?? 'badge-gray';
    $dot = [
        'green' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'blue' => 'bg-sky-500',
        'red' => 'bg-rose-500', 'gray' => 'bg-gray-400',
    ][$tone] ?? 'bg-gray-400';
@endphp

<span class="badge {{ $cls }}">
    @if ($icon)<span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>@endif
    {{ $label ?? $slot }}
</span>
