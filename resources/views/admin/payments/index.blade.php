<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المدفوعات') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('المدفوعات')">
                @can('create', \App\Modules\Payment\Models\Payment::class)
                    <a href="{{ route('admin.payments.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('دفعة جديدة') }}</a>
                @endcan
            </x-admin.header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرقم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الطلب') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الطريقة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المبلغ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($payments as $p)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $p->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $p->order?->number }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $p->method?->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $p->amount }}</td>
                                <td class="py-2 px-3"><x-payment.status :status="$p->status" /></td>
                                <td class="py-2 px-3"><a href="{{ route('admin.payments.show', $p) }}" class="text-indigo-600 hover:underline">{{ __('عرض') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا توجد مدفوعات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payments->links() }}</div>
        </div>
    </div>
</x-app-layout>
