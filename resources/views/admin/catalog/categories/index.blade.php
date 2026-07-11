<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ __('app.modules.catalog') }} — {{ __('الفئات') }}</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('الفئات')">
                @can('create', \App\Modules\Catalog\Models\Category::class)
                    <a href="{{ route('admin.categories.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('فئة جديدة') }}</a>
                @endcan
            </x-admin.header>

            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم...') }}"
                    class="w-full sm:w-72 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b">
                        <tr>
                            <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الفئة الأب') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('فرعية') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('إجراءات') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($categories as $category)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $category->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $category->parent?->name ?? '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $category->children_count }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $category->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $category->is_active ? __('مفعّل') : __('معطّل') }}
                                    </span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="flex items-center gap-2">
                                        @can('update', $category)
                                            <a href="{{ route('admin.categories.edit', $category) }}" class="text-indigo-600 hover:underline">{{ __('تعديل') }}</a>
                                        @endcan
                                        @can('delete', $category)
                                            <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" onsubmit="return confirm('{{ __('تأكيد الحذف؟') }}')">
                                                @csrf @method('DELETE')
                                                <button class="text-rose-600 hover:underline">{{ __('حذف') }}</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('لا توجد فئات.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $categories->links() }}</div>
        </div>
    </div>
</x-app-layout>
