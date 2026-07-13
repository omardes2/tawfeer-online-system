{{--
    Polished empty state — icon, message, optional description + action slot.
    Props: title, description(optional), icon(svg path|null).
--}}
@props(['title' => 'لا توجد بيانات', 'description' => null, 'icon' => null])

<div class="flex flex-col items-center justify-center text-center py-12 px-6">
    <span class="grid place-items-center w-14 h-14 rounded-full bg-gray-100 text-gray-400 mb-4">
        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon ?: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z' }}"/>
        </svg>
    </span>
    <p class="text-sm font-medium text-gray-700">{{ $title }}</p>
    @if ($description)<p class="mt-1 text-sm text-gray-400 max-w-sm">{{ $description }}</p>@endif
    @if (trim($slot) !== '')<div class="mt-4">{{ $slot }}</div>@endif
</div>
