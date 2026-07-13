{{--
    Inline alert box. Props: tone(green|amber|blue|red|gray), title(optional).
--}}
@props(['tone' => 'blue', 'title' => null])

@php
    $map = [
        'green' => ['bg-emerald-50 border-emerald-200 text-emerald-800', 'text-emerald-500'],
        'amber' => ['bg-amber-50 border-amber-200 text-amber-800', 'text-amber-500'],
        'blue'  => ['bg-sky-50 border-sky-200 text-sky-800', 'text-sky-500'],
        'red'   => ['bg-rose-50 border-rose-200 text-rose-800', 'text-rose-500'],
        'gray'  => ['bg-gray-50 border-gray-200 text-gray-700', 'text-gray-400'],
    ][$tone] ?? ['bg-sky-50 border-sky-200 text-sky-800', 'text-sky-500'];
@endphp

<div class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ $map[0] }}">
    <svg class="w-5 h-5 shrink-0 {{ $map[1] }}" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
    <div class="min-w-0">
        @if ($title)<p class="font-semibold mb-0.5">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
