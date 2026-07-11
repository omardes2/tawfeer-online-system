<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $attribute->exists ? __('تعديل سمة') : __('سمة جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $attribute->exists ? route('admin.attributes.update', $attribute) : route('admin.attributes.store') }}" class="space-y-5">
                @csrf @if ($attribute->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $attribute->name) }}" required class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" /></x-admin.field>
                    <x-admin.field :label="__('النوع')" name="type">
                        <select name="type" class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach (['select' => 'قائمة', 'color' => 'لون', 'text' => 'نص', 'number' => 'رقم'] as $val => $lbl)
                                <option value="{{ $val }}" @selected(old('type', $attribute->type ?? 'select') === $val)>{{ __($lbl) }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $attribute->is_active ?? true)) class="rounded border-gray-300 text-indigo-600" />{{ __('مفعّل') }}</label>
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('حفظ') }}</button><a href="{{ route('admin.attributes.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>

        @if ($attribute->exists)
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-4">{{ __('قيم السمة') }}</h3>
                <div class="flex flex-wrap gap-2 mb-4">
                    @forelse ($attribute->values as $value)
                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-gray-100 text-sm text-gray-700">
                            @if ($value->color_hex)<span class="w-3 h-3 rounded-full border" style="background: {{ $value->color_hex }}"></span>@endif
                            {{ $value->label ?? $value->value }}
                            <form method="POST" action="{{ route('admin.attributes.values.destroy', [$attribute, $value]) }}" onsubmit="return confirm('{{ __('حذف القيمة؟') }}')">@csrf @method('DELETE')<button class="text-rose-500 hover:text-rose-700">&times;</button></form>
                        </span>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('لا توجد قيم بعد.') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('admin.attributes.values.store', $attribute) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div><label class="block text-xs text-gray-600 mb-1">{{ __('القيمة') }}</label><input type="text" name="value" required class="rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="block text-xs text-gray-600 mb-1">{{ __('التسمية') }}</label><input type="text" name="label" class="rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="block text-xs text-gray-600 mb-1">{{ __('اللون') }}</label><input type="color" name="color_hex" value="#000000" class="h-9 w-14 rounded-md border-gray-300" /></div>
                    <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('إضافة قيمة') }}</button>
                </form>
                @error('value')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                @error('color_hex')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
        @endif
    </div>
</x-app-layout>
