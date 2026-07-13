<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $tag->exists ? __('تعديل وسم') : __('وسم جديد') }}</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $tag->exists ? route('admin.tags.update', $tag) : route('admin.tags.store') }}" class="space-y-5">
                @csrf @if ($tag->exists) @method('PUT') @endif
                <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $tag->name) }}" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                <x-admin.field :label="__('الرابط (slug) — اختياري')" name="slug"><input type="text" name="slug" value="{{ old('slug', $tag->slug) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tag->is_active ?? true)) class="rounded border-gray-300 text-emerald-600" />{{ __('مفعّل') }}</label>
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button><a href="{{ route('admin.tags.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
