<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المشتريات') }} — {{ __('الموردون') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('الموردون')">
                @can('create', \App\Modules\Purchasing\Models\Supplier::class)
                    <a href="{{ route('admin.purchasing.suppliers.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('مورد جديد') }}</a>
                @endcan
            </x-admin.header>

            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم أو الرمز') }}" class="rounded-md border-gray-300 text-sm w-64" />
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الرمز') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الهاتف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($suppliers as $s)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $s->code }}</td>
                                <td class="py-2 px-3 text-gray-800">{{ $s->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $s->phone }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $s->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $s->is_active ? __('نشط') : __('غير نشط') }}</span>
                                </td>
                                <td class="py-2 px-3 flex gap-3">
                                    @can('update', $s)
                                        <a href="{{ route('admin.purchasing.suppliers.edit', $s) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                                    @endcan
                                    @can('delete', $s)
                                        <form method="POST" action="{{ route('admin.purchasing.suppliers.destroy', $s) }}" onsubmit="return confirm('{{ __('حذف المورد؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا يوجد موردون.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $suppliers->links() }}</div>
        </div>
    </div>
</x-app-layout>
