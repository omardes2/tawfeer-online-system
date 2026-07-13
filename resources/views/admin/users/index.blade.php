<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المستخدمون والموظّفون') }}</h2></x-slot>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <x-admin.header :title="__('المستخدمون')">
                @can('settings.users.create')
                    <a href="{{ route('admin.users.create') }}" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('مستخدم جديد') }}</a>
                @endcan
            </x-admin.header>

            {{-- بحث وفلاتر --}}
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-5 gap-2 mb-4">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('اسم / بريد / هاتف') }}" class="rounded-md border-gray-300 text-sm sm:col-span-2" />
                <select name="role" class="rounded-md border-gray-300 text-sm">
                    <option value="">{{ __('كل الأدوار') }}</option>
                    @foreach ($roles as $r)<option value="{{ $r->name }}" @selected(($filters['role'] ?? '') === $r->name)><x-admin.role-label :name="$r->name" /></option>@endforeach
                </select>
                <select name="status" class="rounded-md border-gray-300 text-sm">
                    <option value="">{{ __('كل الحالات') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('نشط') }}</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('غير نشط') }}</option>
                </select>
                <button class="px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('بحث') }}</button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('الاسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('البريد') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('القسم') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('المسمّى') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الدور') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الحالة') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('إجراء') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @forelse ($users as $u)
                            <tr>
                                <td class="py-2 px-3 text-gray-800">{{ $u->name }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $u->email }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $u->department ?: '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $u->job_title ?: '—' }}</td>
                                <td class="py-2 px-3">
                                    @foreach ($u->roles as $role)<span class="inline-flex px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-xs"><x-admin.role-label :name="$role->name" /></span>@endforeach
                                </td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $u->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $u->is_active ? __('نشط') : __('غير نشط') }}</span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="flex flex-wrap gap-2">
                                        @can('settings.users.update')
                                            <a href="{{ route('admin.users.edit', $u) }}" class="text-emerald-600 hover:underline">{{ __('تعديل') }}</a>
                                            <form method="POST" action="{{ route('admin.users.toggle', $u) }}">@csrf<button class="text-amber-600 hover:underline">{{ $u->is_active ? __('تعطيل') : __('تفعيل') }}</button></form>
                                            <form method="POST" action="{{ route('admin.users.reset_password', $u) }}" onsubmit="return confirm('{{ __('توليد كلمة مرور مؤقّتة؟') }}')">@csrf<button class="text-sky-600 hover:underline">{{ __('كلمة مرور') }}</button></form>
                                        @endcan
                                        @can('settings.users.delete')
                                            <form method="POST" action="{{ route('admin.users.destroy', $u) }}" onsubmit="return confirm('{{ __('حذف المستخدم؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 hover:underline">{{ __('حذف') }}</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('لا يوجد مستخدمون.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $users->links() }}</div>
        </div>
    </div>
</x-app-layout>
