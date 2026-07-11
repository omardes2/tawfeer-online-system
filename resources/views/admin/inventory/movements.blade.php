<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون') }} — {{ __('سجلّ الحركات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.header :title="__('سجلّ الحركات')" />
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الدلو') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الكمية') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($movements as $m)
                            <tr>
                                <td class="py-2 px-3 text-gray-500">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="py-2 px-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">{{ $m->type }}</span></td>
                                <td class="py-2 px-3 text-gray-500">{{ $m->variant?->sku }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $m->warehouse?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $m->bucket }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($m->qty, '0'), '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد حركات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $movements->links() }}</div>
        </div>
    </div>
</x-app-layout>
