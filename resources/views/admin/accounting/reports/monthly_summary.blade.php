<x-report.layout :title="__('ملخّص المصروفات والإيرادات')" :range="$range">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('المصروفات') }} <span class="text-rose-600">({{ number_format($expenses->sum('total'), 2) }})</span></h3>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    @forelse ($expenses as $e)<tr><td class="py-1.5">{{ $e->name }}</td><td class="py-1.5 text-end font-bold">{{ number_format($e->total, 2) }}</td></tr>@empty<tr><td class="py-3 text-gray-400">{{ __('لا مصروفات.') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('الإيرادات') }} <span class="text-emerald-700">({{ number_format($income->sum('total'), 2) }})</span></h3>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    @forelse ($income as $i)<tr><td class="py-1.5">{{ $i->name }}</td><td class="py-1.5 text-end font-bold">{{ number_format($i->total, 2) }}</td></tr>@empty<tr><td class="py-3 text-gray-400">{{ __('لا إيرادات.') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-report.layout>
