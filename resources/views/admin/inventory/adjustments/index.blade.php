<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون') }} — {{ __('تسويات الجرد') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('تسويات الجرد')">
                @can('create', \App\Modules\Inventory\Models\StockAdjustment::class)
                    <a href="{{ route('admin.inventory.adjustments.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('تسوية جديدة') }}</a>
                @endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('النوع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($adjustments as $a)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $a->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $a->warehouse?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $a->type }}</td>
                                <td class="py-2 px-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">{{ $a->status }}</span></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.inventory.adjustments.show', $a) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد تسويات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $adjustments->links() }}</div>
        </div>
    </div>
</x-app-layout>
