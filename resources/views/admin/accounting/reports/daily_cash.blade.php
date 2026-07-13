<x-report.layout :title="__('حركة النقد اليومية')" :range="$range">
    <div class="bg-white shadow-sm rounded-lg p-5 overflow-x-auto">
        <table class="min-w-full text-sm text-right">
            <thead class="text-gray-500 border-b"><tr><th class="py-2 px-3">{{ __('اليوم') }}</th><th class="py-2 px-3">{{ __('وارد') }}</th><th class="py-2 px-3">{{ __('صادر') }}</th><th class="py-2 px-3">{{ __('الصافي') }}</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="py-2 px-3">{{ $r->d }}</td>
                        <td class="py-2 px-3 text-emerald-700">{{ number_format($r->inflow, 2) }}</td>
                        <td class="py-2 px-3 text-rose-600">{{ number_format($r->outflow, 2) }}</td>
                        <td class="py-2 px-3 font-bold {{ ($r->inflow - $r->outflow) < 0 ? 'text-rose-600' : '' }}">{{ number_format($r->inflow - $r->outflow, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا حركة.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-report.layout>
