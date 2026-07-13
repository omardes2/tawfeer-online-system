{{--
    Standalone breadcrumb trail. Props: items(array of [label => url|null]).
--}}
@props(['items' => []])

@if (!empty($items))
    <nav class="flex items-center gap-1.5 text-xs text-gray-500 flex-wrap" aria-label="breadcrumb">
        @foreach ($items as $label => $url)
            @if (!$loop->first)
                <svg class="w-3 h-3 text-gray-300 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            @endif
            @if ($url && !$loop->last)
                <a href="{{ $url }}" class="hover:text-emerald-600 transition">{{ $label }}</a>
            @else
                <span class="text-gray-700 font-medium">{{ $label }}</span>
            @endif
        @endforeach
    </nav>
@endif
