<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('العملاء') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('العملاء')">
                @can('create', \App\Modules\Crm\Models\Customer::class)
                    <a href="{{ route('admin.crm.customers.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('عميل جديد') }}</a>
                @endcan
            </x-admin.header>

            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم أو الهاتف') }}" class="rounded-md border-gray-300 text-sm w-64" />
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الهاتف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('التصنيف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الطلبات') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($customers as $c)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $c->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $c->primary_phone }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $c->category }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $c->orders_count }}</td>
                                <td class="py-2 px-3">
                                    @if ($c->is_blocked)<span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">{{ __('محظور') }}</span>
                                    @elseif ($c->is_high_risk)<span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">{{ __('عالي الخطورة') }}</span>
                                    @else<span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">{{ __('نشط') }}</span>@endif
                                </td>
                                <td class="py-2 px-3"><div class="flex gap-3">
                                    <a href="{{ route('admin.crm.customers.show', $c) }}" class="text-emerald-600 hover:underline">{{ __('عرض') }}</a>
                                    @can('delete', $c)
                                        <form method="POST" action="{{ route('admin.crm.customers.destroy', $c) }}" onsubmit="return confirm('{{ __('تأكيد حذف العميل؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                    @endcan
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-400">{{ __('لا يوجد عملاء.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $customers->links() }}</div>
        </div>
    </div>
</x-app-layout>
