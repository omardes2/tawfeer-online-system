<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $unit->exists ? __('تعديل وحدة') : __('وحدة جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $unit->exists ? route('admin.units.update', $unit) : route('admin.units.store') }}" class="space-y-5">
                @csrf @if ($unit->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $unit->name) }}" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الاسم بالإنجليزية')" name="name_en"><input type="text" name="name_en" value="{{ old('name_en', $unit->name_en) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الرمز')" name="code"><input type="text" name="code" value="{{ old('code', $unit->code) }}" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الرمز المختصر')" name="symbol"><input type="text" name="symbol" value="{{ old('symbol', $unit->symbol) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الوحدة الأساس')" name="base_unit_id">
                        <select name="base_unit_id" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— لا شيء —') }}</option>
                            @foreach ($units as $u)<option value="{{ $u->id }}" @selected(old('base_unit_id', $unit->base_unit_id) == $u->id)>{{ $u->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('معامل التحويل')" name="conversion_factor"><input type="number" step="0.000001" min="0.000001" name="conversion_factor" value="{{ old('conversion_factor', $unit->conversion_factor ?? 1) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $unit->is_active ?? true)) class="rounded border-gray-300 text-emerald-600" />{{ __('مفعّل') }}</label>
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button><a href="{{ route('admin.units.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
