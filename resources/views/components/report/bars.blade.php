@props(['title', 'rows', 'money' => false])
@php $max = collect($rows)->max(fn ($r) => (float) $r['value']) ?: 1; @endphp
<div class="bg-white shadow-sm rounded-lg border border-gray-100 p-4">
    <h3 class="font-semibold text-gray-800 mb-3 text-sm">{{ $title }}</h3>
    <div class="space-y-2">
        @forelse ($rows as $r)
            <div>
                <div class="flex justify-between text-xs mb-0.5">
                    <span class="text-gray-700">{{ $r['label'] }}</span>
                    <span class="font-medium text-gray-900">{{ $money ? number_format((float) $r['value'], 2) : $r['value'] }}</span>
                </div>
                <div class="h-2 bg-gray-100 rounded overflow-hidden">
                    <div class="h-2 bg-indigo-500 rounded" style="width: {{ max(2, round((float) $r['value'] / $max * 100)) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-gray-400 text-sm">{{ __('reports.no_data') }}</p>
        @endforelse
    </div>
</div>
