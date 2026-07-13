<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $product->exists ? __('تعديل منتج') : __('منتج جديد') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" class="space-y-6">
                @csrf @if ($product->exists) @method('PUT') @endif

                {{-- الهوية --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم (عربي)')" name="name"><input type="text" name="name" value="{{ old('name', $product->name) }}" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الاسم (إنجليزي)')" name="name_en"><input type="text" name="name_en" value="{{ old('name_en', $product->name_en) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الرابط (slug) — اختياري')" name="slug"><input type="text" name="slug" value="{{ old('slug', $product->slug) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field label="SKU" name="sku"><input type="text" name="sku" value="{{ old('sku', $product->sku) }}" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الباركود')" name="barcode"><input type="text" name="barcode" value="{{ old('barcode', $product->barcode) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('النوع')" name="type">
                        <select name="type" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (['simple' => 'بسيط', 'variable' => 'متغيّرات'] as $v => $l)<option value="{{ $v }}" @selected(old('type', $product->type ?? 'simple') === $v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                </div>

                {{-- التصنيف --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-admin.field :label="__('الفئة')" name="category_id">
                        <select name="category_id" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— اختر —') }}</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('العلامة التجارية')" name="brand_id">
                        <select name="brand_id" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— بلا —') }}</option>
                            @foreach ($brands as $b)<option value="{{ $b->id }}" @selected(old('brand_id', $product->brand_id) == $b->id)>{{ $b->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('الوحدة')" name="unit_id">
                        <select name="unit_id" required class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('— اختر —') }}</option>
                            @foreach ($units as $u)<option value="{{ $u->id }}" @selected(old('unit_id', $product->unit_id) == $u->id)>{{ $u->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                </div>

                {{-- الأوصاف --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('وصف مختصر (عربي)')" name="short_description"><input type="text" name="short_description" value="{{ old('short_description', $product->short_description) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('وصف مختصر (إنجليزي)')" name="short_description_en"><input type="text" name="short_description_en" value="{{ old('short_description_en', $product->short_description_en) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <x-admin.field :label="__('الوصف (عربي)')" name="description"><textarea name="description" rows="3" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('description', $product->description) }}</textarea></x-admin.field>
                    <x-admin.field :label="__('الوصف (إنجليزي)')" name="description_en"><textarea name="description_en" rows="3" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">{{ old('description_en', $product->description_en) }}</textarea></x-admin.field>
                </div>

                {{-- الحالة والظهور --}}
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    <x-admin.field :label="__('الحالة')" name="status">
                        <select name="status" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (['draft' => 'مسودّة', 'active' => 'مفعّل', 'archived' => 'مؤرشف'] as $v => $l)<option value="{{ $v }}" @selected(old('status', $product->status ?? 'draft') === $v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('الظهور')" name="visibility">
                        <select name="visibility" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (['visible' => 'ظاهر', 'hidden' => 'مخفي'] as $v => $l)<option value="{{ $v }}" @selected(old('visibility', $product->visibility ?? 'visible') === $v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('الترتيب')" name="sort_order"><input type="number" min="0" name="sort_order" value="{{ old('sort_order', $product->sort_order ?? 0) }}" class="w-full rounded-md border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" /></x-admin.field>
                    <label class="flex items-center gap-2 text-sm pb-2"><input type="hidden" name="is_featured" value="0" /><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured)) class="rounded border-gray-300 text-emerald-600" />{{ __('مميّز') }}</label>
                </div>

                {{-- الوسوم والسمات --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الوسوم') }}</label>
                        <div class="flex flex-wrap gap-2 max-h-32 overflow-y-auto p-2 border rounded-md">
                            @php $selTags = old('tag_ids', $product->exists ? $product->tags->pluck('id')->all() : []); @endphp
                            @forelse ($tags as $tag)
                                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selTags)) class="rounded border-gray-300 text-emerald-600" />{{ $tag->name }}</label>
                            @empty
                                <span class="text-xs text-gray-400">{{ __('لا توجد وسوم') }}</span>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('السمات المطبّقة') }}</label>
                        <div class="flex flex-wrap gap-2 max-h-32 overflow-y-auto p-2 border rounded-md">
                            @php $selAttrs = old('attribute_ids', $product->exists ? $product->attributes->pluck('id')->all() : []); @endphp
                            @forelse ($attributes as $attr)
                                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="attribute_ids[]" value="{{ $attr->id }}" @checked(in_array($attr->id, $selAttrs)) class="rounded border-gray-300 text-emerald-600" />{{ $attr->name }}</label>
                            @empty
                                <span class="text-xs text-gray-400">{{ __('لا توجد سمات') }}</span>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- SEO --}}
                <details class="border rounded-md p-3">
                    <summary class="text-sm font-medium text-gray-700 cursor-pointer">{{ __('تحسين محركات البحث (SEO) والبحث') }}</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                        <x-admin.field :label="__('عنوان Meta')" name="meta_title"><input type="text" name="meta_title" value="{{ old('meta_title', $product->meta_title) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('كلمات Meta')" name="meta_keywords"><input type="text" name="meta_keywords" value="{{ old('meta_keywords', $product->meta_keywords) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('وصف Meta')" name="meta_description"><textarea name="meta_description" rows="2" class="w-full rounded-md border-gray-300">{{ old('meta_description', $product->meta_description) }}</textarea></x-admin.field>
                        <x-admin.field :label="__('كلمات البحث')" name="search_keywords"><textarea name="search_keywords" rows="2" class="w-full rounded-md border-gray-300">{{ old('search_keywords', $product->search_keywords) }}</textarea></x-admin.field>
                    </div>
                </details>

                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button><a href="{{ route('admin.products.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>

        {{-- مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044) — اقتراح فقط --}}
        <x-admin.ai-panel :product="$product->exists ? $product : null" />

        {{-- معرض الصور (بعد الإنشاء) --}}
        @if ($product->exists)
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-4">{{ __('صور المنتج') }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                    @forelse ($product->images as $image)
                        <div class="relative border rounded-lg overflow-hidden group">
                            <img src="{{ $image->url() }}" alt="{{ $image->alt }}" class="w-full h-28 object-cover bg-gray-100" />
                            @if ($image->is_primary)<span class="absolute top-1 start-1 px-2 py-0.5 rounded-full bg-emerald-600 text-white text-xs">{{ __('أساسية') }}</span>@endif
                            <div class="absolute bottom-0 inset-x-0 bg-black/40 flex justify-center gap-2 p-1 opacity-0 group-hover:opacity-100 transition">
                                @unless ($image->is_primary)
                                    <form method="POST" action="{{ route('admin.products.images.primary', [$product, $image]) }}">@csrf<button class="text-white text-xs hover:underline">{{ __('تعيين أساسية') }}</button></form>
                                @endunless
                                <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $image]) }}" onsubmit="return confirm('{{ __('حذف الصورة؟') }}')">@csrf @method('DELETE')<button class="text-white text-xs hover:underline">{{ __('حذف') }}</button></form>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 col-span-full">{{ __('لا توجد صور بعد.') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div><label class="block text-xs text-gray-600 mb-1">{{ __('صورة') }}</label><input type="file" name="image" accept="image/*" required class="text-sm" /></div>
                    <div><label class="block text-xs text-gray-600 mb-1">{{ __('نص بديل') }}</label><input type="text" name="alt" class="rounded-md border-gray-300 text-sm" /></div>
                    <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_primary" value="1" class="rounded border-gray-300 text-emerald-600" />{{ __('أساسية') }}</label>
                    <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('رفع') }}</button>
                    @error('image')<p class="w-full text-xs text-rose-600">{{ $message }}</p>@enderror
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
