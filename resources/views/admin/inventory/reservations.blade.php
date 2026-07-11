<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون') }} — {{ __('الحجوزات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('حجوزات المخزون')" />
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الكمية') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($reservations as $r)
                            <tr>
                                <td class="py-2 px-3 text-gray-500">{{ $r->variant?->sku }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $r->warehouse?->name }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($r->qty, '0'), '.') }}</td>
                                <td class="py-2 px-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $r->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $r->status }}</span></td>
                                <td class="py-2 px-3">
                                    @if ($r->status === 'active')
                                        @can('release', $r)
                                            <form method="POST" action="{{ route('admin.inventory.reservations.release', $r) }}" onsubmit="return confirm('{{ __('تحرير الحجز؟') }}')">@csrf<button class="text-rose-600 hover:underline">{{ __('تحرير') }}</button></form>
                                        @endcan
                                    @else — @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد حجوزات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $reservations->links() }}</div>
        </div>
    </div>
</x-app-layout>
