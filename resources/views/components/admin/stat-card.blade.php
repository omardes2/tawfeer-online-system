{{--
    Statistic card — label, value, optional icon, tone (color), and delta/hint.
    Props: label, value, icon(svg path|null), tone(green|amber|blue|red|gray),
           hint(string|null), money(bool).
--}}
@props(['label', 'value' => '0', 'icon' => null, 'tone' => 'gray', 'hint' => null, 'money' => false])

@php
    $tones = [
        'green' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'bar' => 'bg-emerald-500'],
        'amber' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'bar' => 'bg-amber-500'],
        'blue'  => ['bg' => 'bg-sky-50', 'text' => 'text-sky-600', 'bar' => 'bg-sky-500'],
        'red'   => ['bg' => 'bg-rose-50', 'text' => 'text-rose-600', 'bar' => 'bg-rose-500'],
        'gray'  => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'bar' => 'bg-gray-400'],
    ][$tone] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'bar' => 'bg-gray-400'];
@endphp

<div class="admin-card relative overflow-hidden">
    <span class="absolute inset-y-0 start-0 w-1 {{ $tones['bar'] }}"></span>
    <div class="p-4 md:p-5 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-gray-500 truncate">{{ $label }}</p>
            <p class="mt-1.5 text-2xl font-bold text-gray-900 tabular-nums">
                {{ $money ? number_format((float) $value, 2) : $value }}
                @if ($money)<span class="text-sm font-medium text-gray-400">{{ __('ر.س') }}</span>@endif
            </p>
            @if ($hint)<p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>@endif
        </div>
        @if ($icon)
            <span class="grid place-items-center w-10 h-10 rounded-lg {{ $tones['bg'] }} {{ $tones['text'] }} shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
            </span>
        @endif
    </div>
</div>
