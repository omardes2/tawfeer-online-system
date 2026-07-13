<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('roles.management') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('roles.title')">
                @can('settings.roles.manage')
                    <a href="{{ route('admin.roles.create') }}" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('roles.new') }}</a>
                @endcan
            </x-admin.header>

            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('بحث بالاسم') }}" class="rounded-md border-gray-300 text-sm w-64" />
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الدور') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الصلاحيات') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المستخدمون') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($roles as $role)
                            <tr x-data="{ copy: false }">
                                <td class="py-2 px-3 text-gray-800 font-medium"><x-admin.role-label :name="$role->name" /> <span class="text-gray-400 text-xs">({{ $role->name }})</span></td>
                                <td class="py-2 px-3 text-gray-500">{{ $role->permissions_count }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $role->users_count }}</td>
                                <td class="py-2 px-3">
                                    @can('settings.roles.manage')
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('admin.roles.edit', $role) }}" class="text-indigo-600 hover:underline">{{ __('تعديل') }}</a>
                                            <button type="button" @click="copy = !copy" class="text-sky-600 hover:underline">{{ __('نسخ') }}</button>
                                            @unless (in_array($role->name, ['admin']))
                                                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('{{ __('حذف الدور؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                            @endunless
                                        </div>
                                        <div x-show="copy" x-cloak class="mt-2">
                                            <form method="POST" action="{{ route('admin.roles.copy', $role) }}" class="flex gap-2">
                                                @csrf
                                                <input type="text" name="name" placeholder="{{ __('اسم الدور الجديد') }}" required pattern="[a-z0-9_\-]+" class="rounded-md border-gray-300 text-sm" />
                                                <button class="px-3 py-1.5 bg-sky-600 text-white text-xs rounded-md">{{ __('إنشاء نسخة') }}</button>
                                            </form>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('لا توجد أدوار.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
