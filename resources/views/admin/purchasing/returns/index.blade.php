<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المشتريات') }} — {{ __('مرتجعات الموردين') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('مرتجعات الموردين')">
                @can('create', \App\Modules\Purchasing\Models\SupplierReturn::class)
                    <a href="{{ route('admin.purchasing.returns.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('مرتجع جديد') }}</a>
                @endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المورد') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($returns as $r)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $r->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $r->supplier?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $r->warehouse?->name }}</td>
                                <td class="py-2 px-3"><x-purchasing.status :status="$r->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.purchasing.returns.show', $r) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد مرتجعات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $returns->links() }}</div>
        </div>
    </div>
</x-app-layout>
