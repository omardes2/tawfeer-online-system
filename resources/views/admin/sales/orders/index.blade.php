<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المبيعات') }} — {{ __('الطلبات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('طلبات البيع')">
                @can('create', \App\Modules\Sales\Models\Order::class)
                    <a href="{{ route('admin.sales.orders.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('طلب جديد') }}</a>
                @endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('العميل') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستودع') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الإجمالي') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($orders as $o)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $o->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->customer_name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->warehouse?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $o->total }}</td>
                                <td class="py-2 px-3"><x-sales.status :status="$o->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.sales.orders.show', $o) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد طلبات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        </div>
    </div>
</x-app-layout>
