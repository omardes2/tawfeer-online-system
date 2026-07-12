@props(['items'])
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    @foreach ($items as $it)
        <div class="bg-white shadow-sm rounded-lg border border-gray-100 p-4">
            <p class="text-2xl font-bold text-gray-900">{{ $it['value'] }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $it['label'] }}</p>
        </div>
    @endforeach
</div>
