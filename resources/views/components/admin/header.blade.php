{{--
    Page header — title, optional description + breadcrumb, and an actions slot
    (primary/secondary buttons). Backward compatible: existing usages that pass
    only :title and a default slot for actions keep working.

    Props:
      title        (string, required)
      description  (string, optional)
      breadcrumbs  (array of [label => url|null], optional)
--}}
@props(['title', 'description' => null, 'breadcrumbs' => []])

<div class="mb-6">
    @if (!empty($breadcrumbs))
        <nav class="mb-2 flex items-center gap-1.5 text-xs text-gray-500 flex-wrap" aria-label="breadcrumb">
            @foreach ($breadcrumbs as $label => $url)
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

    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="min-w-0">
            <h1 class="text-xl md:text-2xl font-bold text-gray-900">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">{{ $slot }}</div>
    </div>
</div>
