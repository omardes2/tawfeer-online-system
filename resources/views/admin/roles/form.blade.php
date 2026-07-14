<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $role->exists ? __('roles.edit') : __('roles.new') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8" x-data="{ q: '' }">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="space-y-5">
                @csrf @if ($role->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('roles.name_key')" name="name">
                        <input type="text" name="name" value="{{ old('name', $role->name) }}" required pattern="[a-z0-9_\-]+"
                            @if(in_array($role->name, ['admin'])) readonly @endif
                            class="w-full rounded-md border-gray-300 font-mono" />
                        <p class="text-xs text-gray-400 mt-1">{{ __('أحرف صغيرة/أرقام/شرطة فقط') }}</p>
                    </x-admin.field>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('بحث في الصلاحيات') }}</label>
                        <input type="text" x-model="q" placeholder="{{ __('تصفية…') }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                </div>

                {{-- الصلاحيات مجمّعة حسب الوحدة --}}
                <div class="space-y-4">
                    @foreach ($groups as $module => $perms)
                        <div class="border rounded-lg p-4" x-show="$el.querySelectorAll('[data-perm]:not([hidden])').length > 0 || q === ''">
                            @php $glabel = __('perm_groups.'.$module); $glabel = $glabel === 'perm_groups.'.$module ? \Illuminate\Support\Str::headline($module) : $glabel; @endphp
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="font-semibold text-gray-700">{{ $glabel }}</h3>
                                <label class="text-xs text-gray-500 flex items-center gap-1">
                                    <input type="checkbox" onclick="this.closest('.border').querySelectorAll('input[name=&quot;permissions[]&quot;]').forEach(c => c.checked = this.checked)" class="rounded border-gray-300" />
                                    {{ __('تحديد الكل') }}
                                </label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                @foreach ($perms as $perm)
                                    <label data-perm x-show="q === '' || '{{ $perm->name }}'.toLowerCase().includes(q.toLowerCase()) || '{{ \App\Support\PermissionLabel::for($perm->name) }}'.includes(q)"
                                        class="inline-flex items-start gap-2 text-sm">
                                        <input type="checkbox" name="permissions[]" value="{{ $perm->name }}"
                                            @checked(in_array($perm->name, old('permissions', $assigned)))
                                            class="mt-0.5 rounded border-gray-300 text-emerald-600" />
                                        <span class="leading-tight">
                                            <span class="text-gray-800">{{ \App\Support\PermissionLabel::for($perm->name) }}</span>
                                            <span class="block text-gray-400 font-mono text-[10px]">{{ $perm->name }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.roles.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
