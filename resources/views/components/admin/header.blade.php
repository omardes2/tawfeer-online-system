@props(['title'])
<div class="flex items-center justify-between gap-4 mb-6 flex-wrap">
    <h1 class="text-xl font-semibold text-gray-800">{{ $title }}</h1>
    <div class="flex items-center gap-2">{{ $slot }}</div>
</div>
