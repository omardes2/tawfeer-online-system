<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ $category->exists ? __('تعديل فئة') : __('فئة جديدة') }}</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" enctype="multipart/form-data" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="space-y-5">
                @csrf
                @if ($category->exists) @method('PUT') @endif

                {{-- أيقونة الفئة (تظهر على الموقع وفي قائمة الفئات) --}}
                <x-admin.field :label="__('أيقونة الفئة')" name="icon" :hint="__('PNG / JPG / SVG — بحد أقصى 1 ميغابايت')">
                    <div class="flex items-center gap-4">
                        <span class="grid place-items-center w-16 h-16 rounded-lg bg-gray-100 overflow-hidden shrink-0">
                            @if ($category->iconUrl())
                                <img src="{{ $category->iconUrl() }}" alt="" class="w-full h-full object-cover" id="icon-preview" />
                            @else
                                <svg class="w-7 h-7 text-gray-300" id="icon-preview-placeholder" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.41-1.41a2.25 2.25 0 0 1 3.18 0l2.16 2.16M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                            @endif
                        </span>
                        <input type="file" name="icon" accept="image/*"
                               onchange="const p=document.getElementById('icon-preview')||document.getElementById('icon-preview-placeholder'); if(this.files[0]){const u=URL.createObjectURL(this.files[0]); if(p.tagName==='IMG'){p.src=u}else{const img=new Image(); img.src=u; img.className='w-full h-full object-cover'; p.replaceWith(img);}}"
                               class="text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-emerald-700 hover:file:bg-emerald-100" />
                    </div>
                </x-admin.field>

                <x-admin.field :label="__('الاسم')" name="name">
                    <input type="text" name="name" value="{{ old('name', $category->name) }}" required
                        class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>

                <x-admin.field :label="__('الرابط (slug) — اختياري')" name="slug">
                    <input type="text" name="slug" value="{{ old('slug', $category->slug) }}"
                        class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>

                <x-admin.field :label="__('الفئة الأب')" name="parent_id">
                    <select name="parent_id" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('— بلا أب —') }}</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" @selected(old('parent_id', $category->parent_id) == $parent->id)>{{ $parent->name }}</option>
                        @endforeach
                    </select>
                </x-admin.field>

                <x-admin.field :label="__('الوصف')" name="description">
                    <textarea name="description" rows="3" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('description', $category->description) }}</textarea>
                </x-admin.field>

                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0" />
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true)) class="rounded border-gray-300 text-emerald-600" />
                    {{ __('مفعّل') }}
                </label>

                <div class="flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.categories.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
