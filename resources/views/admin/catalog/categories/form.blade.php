<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ $category->exists ? __('تعديل فئة') : __('فئة جديدة') }}</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="space-y-5">
                @csrf
                @if ($category->exists) @method('PUT') @endif

                <x-admin.field :label="__('الاسم')" name="name">
                    <input type="text" name="name" value="{{ old('name', $category->name) }}" required
                        class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                </x-admin.field>

                <x-admin.field :label="__('الرابط (slug) — اختياري')" name="slug">
                    <input type="text" name="slug" value="{{ old('slug', $category->slug) }}"
                        class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" />
                </x-admin.field>

                <x-admin.field :label="__('الفئة الأب')" name="parent_id">
                    <select name="parent_id" class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('— بلا أب —') }}</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" @selected(old('parent_id', $category->parent_id) == $parent->id)>{{ $parent->name }}</option>
                        @endforeach
                    </select>
                </x-admin.field>

                <x-admin.field :label="__('الوصف')" name="description">
                    <textarea name="description" rows="3" class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $category->description) }}</textarea>
                </x-admin.field>

                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0" />
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true)) class="rounded border-gray-300 text-indigo-600" />
                    {{ __('مفعّل') }}
                </label>

                <div class="flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.categories.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
