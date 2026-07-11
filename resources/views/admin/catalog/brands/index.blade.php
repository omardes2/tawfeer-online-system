<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('app.modules.catalog') }} — {{ __('العلامات التجارية') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('العلامات التجارية')">
                @can('create', \App\Modules\Catalog\Models\Brand::class)
                    <a href="{{ route('admin.brands.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('علامة جديدة') }}</a>
                @endcan
            </x-admin.header>
            <form method="GET" class="mb-4"><input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('بحث...') }}" class="w-full sm:w-72 rounded-md border-gray-300 text-sm" /></form>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الرابط') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($brands as $brand)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $brand->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $brand->slug }}</td>
                                <td class="py-2 px-3"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $brand->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $brand->is_active ? __('مفعّل') : __('معطّل') }}</span></td>
                                <td class="py-2 px-3"><div class="flex gap-2">
                                    @can('update', $brand)<a href="{{ route('admin.brands.edit', $brand) }}" class="text-indigo-600 hover:underline">{{ __('تعديل') }}</a>@endcan
                                    @can('delete', $brand)<form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>@endcan
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا توجد علامات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $brands->links() }}</div>
        </div>
    </div>
</x-app-layout>
