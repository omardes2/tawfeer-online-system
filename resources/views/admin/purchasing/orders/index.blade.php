<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المشتريات') }} — {{ __('أوامر الشراء') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('أوامر الشراء')">
                @can('create', \App\Modules\Purchasing\Models\PurchaseOrder::class)
                    <a href="{{ route('admin.purchasing.orders.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('أمر شراء جديد') }}</a>
                @endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المورد') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التاريخ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($orders as $o)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $o->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->supplier?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->warehouse?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->order_date?->toDateString() }}</td>
                                <td class="py-2 px-3"><x-purchasing.status :status="$o->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.purchasing.orders.show', $o) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد أوامر شراء.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        </div>
    </div>
</x-app-layout>
