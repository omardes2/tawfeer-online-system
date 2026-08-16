<x-app-layout :title="$product->exists ? __('تعديل منتج') : __('منتج جديد')">
    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');
        $inputClass = 'w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500';
    @endphp

    <x-admin.header
        :title="$product->exists ? __('تعديل منتج') : __('منتج جديد')"
        :description="__('بيانات الصنف وسعره ومخزونه كما تظهر في المتجر واللوحة.')"
        :breadcrumbs="[
            __('الرئيسية') => route('admin.dashboard'),
            __('المنتجات') => route('admin.products.index'),
            ($product->exists ? $product->name : __('منتج جديد')) => null,
        ]" />

    <x-admin.flash />

    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
          class="space-y-5">
        @csrf @if ($product->exists) @method('PUT') @endif

        {{-- معلومات أساسية --}}
        <x-admin.form-section :title="__('معلومات أساسية')" :cols="2">
            <x-slot:icon>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.852l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            </x-slot:icon>

            <x-admin.field :label="__('اسم المنتج')" name="name" required>
                <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                       placeholder="{{ __('أدخل اسم المنتج') }}" class="{{ $inputClass }}" />
            </x-admin.field>
            <x-admin.field :label="__('الباركود')" name="barcode">
                <input type="text" name="barcode" value="{{ old('barcode', $product->barcode) }}" class="{{ $inputClass }}" />
            </x-admin.field>
            <x-admin.field :label="__('الفئة')" name="category_id" required>
                <select name="category_id" required class="{{ $inputClass }}">
                    <option value="">{{ __('— اختر —') }}</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('العلامة التجارية')" name="brand_id">
                <select name="brand_id" class="{{ $inputClass }}">
                    <option value="">{{ __('— بلا —') }}</option>
                    @foreach ($brands as $b)<option value="{{ $b->id }}" @selected(old('brand_id', $product->brand_id) == $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('الوحدة')" name="unit_id" required>
                <select name="unit_id" required class="{{ $inputClass }}">
                    <option value="">{{ __('— اختر —') }}</option>
                    @foreach ($units as $u)<option value="{{ $u->id }}" @selected(old('unit_id', $product->unit_id) == $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('الرابط (slug)')" name="slug">
                <input type="text" name="slug" value="{{ old('slug', $product->slug) }}"
                       placeholder="{{ __('يُولَّد من الاسم إن تُرك فارغًا') }}" class="{{ $inputClass }}" />
            </x-admin.field>
        </x-admin.form-section>

        {{-- الوصف --}}
        <x-admin.form-section :title="__('الوصف')">
            <x-slot:icon>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
            </x-slot:icon>

            <x-admin.field :label="__('وصف مختصر')" name="short_description">
                <input type="text" name="short_description" value="{{ old('short_description', $product->short_description) }}"
                       placeholder="{{ __('سطر واحد يظهر تحت اسم المنتج') }}" class="{{ $inputClass }}" />
            </x-admin.field>
            <x-admin.field :label="__('الوصف التفصيلي')" name="description">
                <textarea name="description" rows="5" placeholder="{{ __('أدخل وصف المنتج التفصيلي…') }}"
                          class="{{ $inputClass }}">{{ old('description', $product->description) }}</textarea>
            </x-admin.field>
        </x-admin.form-section>

        {{-- التسعير --}}
        @php
            $v = $product->defaultVariant;
            // «سعر البيع» = السعر الأصلي المعروض في صفحة المخزن (products.retail_price المُزامَن مع المتغيّر).
            $sellPrice = old('retail_price', $v?->retail_price ?? $product->retail_price);
            $promoPrice = old('promo_price', $v?->promo_price ?? $product->promo_price);
            $hasDiscount = is_numeric($sellPrice) && is_numeric($promoPrice)
                && (float) $promoPrice > 0 && (float) $promoPrice < (float) $sellPrice;
            $discountPct = $hasDiscount ? (int) round((1 - $promoPrice / $sellPrice) * 100) : 0;
        @endphp
        <x-admin.form-section :title="__('التسعير')" :cols="2">
            <x-slot:icon>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
            </x-slot:icon>

            <div class="rounded-lg border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between bg-gray-50 px-4 py-2 border-b border-gray-200">
                    <span class="text-sm font-medium text-gray-700">{{ __('السعر الأصلي (سعر البيع)') }}</span>
                    <span class="text-xs text-gray-400">{{ __('السعر المعروض قبل الخصم') }}</span>
                </div>
                <div class="px-4 py-3">
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="retail_price" value="{{ $sellPrice }}"
                               class="{{ $inputClass }} ps-12 tabular-nums" />
                        <span class="absolute inset-y-0 start-0 flex items-center px-3 text-gray-400 text-sm">{{ $sym }}</span>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-rose-200 overflow-hidden">
                <div class="flex items-center justify-between bg-rose-50 px-4 py-2 border-b border-rose-200">
                    <span class="text-sm font-medium text-rose-700">{{ __('سعر الخصم (العرض)') }}</span>
                    @if ($hasDiscount)
                        <span class="text-xs text-rose-500">{{ __('خصم') }} {{ $discountPct }}%</span>
                    @else
                        <span class="text-xs text-gray-400">{{ __('اتركه فارغًا إن لا يوجد عرض') }}</span>
                    @endif
                </div>
                <div class="px-4 py-3">
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="promo_price" value="{{ $promoPrice }}"
                               placeholder="{{ __('مثال: 80') }}"
                               class="{{ $inputClass }} ps-12 tabular-nums focus:border-rose-400 focus:ring-rose-400" />
                        <span class="absolute inset-y-0 start-0 flex items-center px-3 text-gray-400 text-sm">{{ $sym }}</span>
                    </div>
                    @if ($hasDiscount)
                        <p class="mt-2 text-xs text-gray-500">
                            {{ __('سيظهر في الموقع:') }}
                            <span class="text-gray-400 line-through">{{ $sellPrice }}</span>
                            <span class="text-rose-600 font-semibold mx-1">{{ $promoPrice }}</span>
                            {{ $sym }}
                        </p>
                    @endif
                </div>
            </div>
        </x-admin.form-section>

        {{-- المخزون --}}
        <x-admin.form-section :title="__('المخزون')" :cols="2">
            <x-slot:icon>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </x-slot:icon>

            <x-admin.field :label="__('حدّ التنبيه بالنقص')" name="reorder_level">
                <input type="number" step="0.001" min="0" name="reorder_level"
                       value="{{ old('reorder_level', $product->reorder_level !== null ? (float) $product->reorder_level : '') }}"
                       placeholder="{{ __('الافتراضي: :n', ['n' => (float) \App\Modules\Foundation\Services\Settings::get('inventory.default_reorder_level', 0)]) }}"
                       class="{{ $inputClass }}" />
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('يظهر الصنف في «تنبيهات النقص» عندما ينزل المتوفّر إلى هذا الحدّ أو دونه. اتركه فارغًا لاستخدام الحدّ الافتراضي من الإعدادات.') }}
                </p>
            </x-admin.field>
        </x-admin.form-section>

        {{-- السمات: اختيارها هنا هو ما يفتح مصفوفة المقاسات/الألوان أدناه --}}
        <x-admin.form-section :title="__('السمات المطبّقة')"
                              :description="__('اختر السمات (مقاس/لون) لتظهر تركيباتها في «الخيارات والمتغيّرات».')">
            <x-slot:icon>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/></svg>
            </x-slot:icon>

            <div class="flex flex-wrap gap-2">
                @php $selAttrs = old('attribute_ids', $product->exists ? $product->attributes->pluck('id')->all() : []); @endphp
                @forelse ($attributes as $attr)
                    <label class="inline-flex items-center gap-1.5 text-sm rounded-lg border border-gray-200 px-3 py-1.5 cursor-pointer hover:border-emerald-400 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                        <input type="checkbox" name="attribute_ids[]" value="{{ $attr->id }}" @checked(in_array($attr->id, $selAttrs))
                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                        {{ $attr->name }}
                    </label>
                @empty
                    <span class="text-xs text-gray-400">{{ __('لا توجد سمات') }}</span>
                @endforelse
            </div>
        </x-admin.form-section>

        {{-- SEO --}}
        <details class="admin-card p-5 md:p-6">
            <summary class="font-semibold text-gray-900 cursor-pointer">{{ __('تحسين محركات البحث (SEO) والبحث') }}</summary>
            <div class="grid gap-5 md:grid-cols-2 mt-5 pt-5 border-t border-gray-100">
                <x-admin.field :label="__('عنوان Meta')" name="meta_title"><input type="text" name="meta_title" value="{{ old('meta_title', $product->meta_title) }}" class="{{ $inputClass }}" /></x-admin.field>
                <x-admin.field :label="__('كلمات Meta')" name="meta_keywords"><input type="text" name="meta_keywords" value="{{ old('meta_keywords', $product->meta_keywords) }}" class="{{ $inputClass }}" /></x-admin.field>
                <x-admin.field :label="__('وصف Meta')" name="meta_description"><textarea name="meta_description" rows="2" class="{{ $inputClass }}">{{ old('meta_description', $product->meta_description) }}</textarea></x-admin.field>
                <x-admin.field :label="__('كلمات البحث')" name="search_keywords"><textarea name="search_keywords" rows="2" class="{{ $inputClass }}">{{ old('search_keywords', $product->search_keywords) }}</textarea></x-admin.field>
            </div>
        </details>

        {{--
            شريط الإجراءات بطاقةً في نهاية النموذج لا ملتصقًا بأسفل الشاشة:
            الصفحة تُكمل بعد النموذج بأقسامٍ لها حفظُها (المتغيّرات والوسائط)،
            فزرٌّ عائم يظلّ معك فيها يوهم أنه يحفظها هي.
        --}}
        <div class="admin-card p-4 flex items-center gap-2">
            <button class="btn-primary">{{ $product->exists ? __('حفظ التعديلات') : __('حفظ المنتج') }}</button>
            <a href="{{ route('admin.products.index') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
            <span class="ms-auto text-xs text-gray-400">{{ __('الأقسام أدناه لها حفظها الخاص.') }}</span>
        </div>
    </form>

    {{--
        أقسامٌ لها حفظُها الخاص خارج النموذج. حاوية واحدة بنفس تباعده وعرضه
        فتبدو الصفحة قطعةً واحدة — كانت بلا حاوية فبانت بعرضٍ مخالف.
    --}}
    <div class="space-y-5 mt-5">
        {{-- مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044) — اقتراح فقط --}}
        <x-admin.ai-panel :product="$product->exists ? $product : null" />

        {{-- الخيارات والمتغيّرات (مقاسات/ألوان) — مصفوفة حيّة: اختر القيم فتظهر السعر والكمية فورًا --}}
        @if ($product->exists)
            <div class="admin-card p-5 md:p-6" x-data="variantMatrix(@js($variantMatrix))">
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('الخيارات والمتغيّرات') }}</h3>
                <p class="text-xs text-gray-400 mb-5">{{ __('اختر القيم (مقاس/لون) فتظهر التركيبات فورًا. عدّل السعر والكمية لكل تركيبة ثم اضغط «حفظ المتغيّرات».') }}</p>

                @if (empty($variantMatrix['attributes']))
                    <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 text-sm p-3">
                        {{ __('لا توجد سمات بقيم بعد. أضِف سمة (مثل «مقاسات») وقيمها من «الخيارات والمتغيّرات» في القائمة الجانبية، ثم عُد هنا.') }}
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.products.variants.sync', $product) }}">
                        @csrf

                        {{-- اختيار القيم لكل سمة (حيّ، بلا حفظ مسبق) --}}
                        <div class="space-y-4 border border-gray-200 rounded-lg p-4 mb-5">
                            <template x-for="attr in attributes" :key="attr.id">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2" x-text="attr.name"></label>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="val in attr.values" :key="val.id">
                                            <button type="button" @click="toggle(attr.id, val.id)"
                                                    :class="isSel(attr.id, val.id) ? 'border-emerald-600 ring-1 ring-emerald-600 text-emerald-700 bg-emerald-50' : 'border-gray-300 text-gray-700 bg-gray-50'"
                                                    class="inline-flex items-center gap-1.5 text-sm border rounded-md px-2.5 py-1 hover:border-emerald-400">
                                                <template x-if="val.color"><span class="inline-block w-3 h-3 rounded-full border border-gray-300" :style="`background:${val.color}`"></span></template>
                                                <span x-text="val.label"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- جدول التركيبات الحيّة: سعر + كمية لكل متغيّر --}}
                        <template x-if="rows.length > 0">
                            <div class="space-y-2">
                                <div class="hidden sm:grid grid-cols-12 gap-2 text-xs font-medium text-gray-500 px-2">
                                    <span class="col-span-6">{{ __('الخيار') }}</span>
                                    <span class="col-span-3">{{ __('السعر') }}</span>
                                    <span class="col-span-3">{{ __('الكمية') }}</span>
                                </div>
                                <template x-for="(row, i) in rows" :key="row.key">
                                    <div class="grid grid-cols-2 sm:grid-cols-12 gap-2 items-center border border-gray-100 rounded-lg p-2">
                                        <div class="col-span-2 sm:col-span-6 text-sm font-medium text-gray-800" x-text="row.label"></div>
                                        <template x-for="vid in row.values" :key="vid">
                                            <input type="hidden" :name="`combos[${i}][values][]`" :value="vid" />
                                        </template>
                                        <div class="sm:col-span-3">
                                            <input type="number" step="0.01" min="0" :name="`combos[${i}][price]`" x-model.number="cells[row.key].price"
                                                   class="w-full rounded-md border-gray-300 text-sm" placeholder="{{ __('السعر') }}" />
                                        </div>
                                        <div class="sm:col-span-3">
                                            <input type="number" step="1" min="0" :name="`combos[${i}][stock]`" x-model.number="cells[row.key].stock"
                                                   class="w-full rounded-md border-gray-300 text-sm" placeholder="{{ __('الكمية') }}" />
                                        </div>
                                    </div>
                                </template>

                                {{-- مطابقة الكميات: المصفوفة توزّع كمية الصنف ولا تضيف إليها --}}
                                <div class="pt-3 mt-3 border-t border-gray-100" x-show="originalQty > 0" x-cloak>
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                                         :class="qtyMatches ? 'text-emerald-700' : 'text-rose-600'">
                                        <span>{{ __('العدد الأصلي') }}: <span class="font-semibold" x-text="originalQty"></span></span>
                                        <span>{{ __('مجموع المُدخل') }}: <span class="font-semibold" x-text="enteredQty"></span></span>
                                        <span x-show="!qtyMatches">
                                            ({{ __('الفرق') }}: <span class="font-semibold" x-text="(enteredQty - originalQty) > 0 ? '+' + (enteredQty - originalQty) : (enteredQty - originalQty)"></span>)
                                        </span>
                                        <span x-show="qtyMatches" class="font-semibold">✓ {{ __('مطابق') }}</span>
                                    </div>
                                    <p x-show="!qtyMatches" class="text-xs text-rose-500 mt-1">
                                        {{ __('يجب أن يساوي مجموع كميات المقاسات كمية الصنف تمامًا — لا زيادة ولا نقصان.') }}
                                    </p>
                                </div>

                                <div class="pt-3">
                                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                            :disabled="!qtyMatches">{{ __('حفظ المتغيّرات') }}</button>
                                    <span class="text-xs text-gray-400 ms-2"><span x-text="rows.length"></span> {{ __('تركيبة') }}</span>
                                </div>
                            </div>
                        </template>

                        <template x-if="rows.length === 0">
                            <p class="text-sm text-gray-400">{{ __('اختر قيمة واحدة على الأقل لإظهار المتغيّرات.') }}</p>
                        </template>
                    </form>
                @endif
            </div>
        @endif

        {{-- الملفات والوسائط (بعد الإنشاء) — على نمط Files & Media --}}
        @if ($product->exists)
            @php
                $thumbnail = $product->images->firstWhere('is_primary', true);
                $gallery = $product->images->where('is_primary', false);
            @endphp
            <div class="admin-card p-5 md:p-6">
                <h3 class="font-semibold text-gray-800 mb-1">{{ __('الملفات والوسائط') }}</h3>
                <p class="text-xs text-gray-400 mb-5">{{ __('الصورة المصغّرة تظهر في قوائم الموقع وبطاقة المنتج، وألبوم الصور يظهر داخل صفحة المنتج.') }}</p>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- الصورة المصغّرة (Thumbnail) --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('الصورة المصغّرة') }}</label>
                        @if ($thumbnail)
                            <div class="relative w-40 h-40 rounded-lg border border-gray-200 overflow-hidden group">
                                <img src="{{ $thumbnail->url() }}" alt="{{ $thumbnail->alt }}" class="w-full h-full object-cover bg-gray-100" />
                                <div class="absolute inset-x-0 bottom-0 bg-black/50 flex justify-center gap-3 p-1.5 opacity-0 group-hover:opacity-100 transition">
                                    <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $thumbnail]) }}" onsubmit="return confirm('{{ __('حذف الصورة المصغّرة؟') }}')">@csrf @method('DELETE')<button class="text-white text-xs hover:underline">{{ __('حذف') }}</button></form>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data" class="mt-2">
                                @csrf
                                <input type="hidden" name="is_primary" value="1" />
                                <input type="file" name="image" accept="image/*" class="hidden" id="thumb-replace" onchange="this.form.submit()" />
                                <label for="thumb-replace" class="text-xs text-emerald-600 hover:underline cursor-pointer">{{ __('استبدال الصورة المصغّرة') }}</label>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="is_primary" value="1" />
                                <input type="file" name="image" accept="image/*" class="hidden" id="thumb-add" onchange="this.form.submit()" />
                                <label for="thumb-add" class="flex flex-col items-center justify-center w-40 h-40 rounded-lg border-2 border-dashed border-gray-300 text-gray-400 hover:border-emerald-400 hover:text-emerald-500 cursor-pointer transition">
                                    <svg class="w-8 h-8 mb-1" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    <span class="text-xs">{{ __('إضافة صورة مصغّرة') }}</span>
                                    <span class="text-[10px] text-gray-300 mt-0.5">300 × 300</span>
                                </label>
                            </form>
                        @endif
                    </div>

                    {{-- ألبوم الصور (Gallery) --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('ألبوم الصور') }}</label>
                        <div class="flex flex-wrap gap-3">
                            @foreach ($gallery as $image)
                                <div class="relative w-24 h-24 rounded-lg border border-gray-200 overflow-hidden group">
                                    <img src="{{ $image->url() }}" alt="{{ $image->alt }}" class="w-full h-full object-cover bg-gray-100" />
                                    <div class="absolute inset-x-0 bottom-0 bg-black/50 flex justify-center gap-2 p-1 opacity-0 group-hover:opacity-100 transition">
                                        <form method="POST" action="{{ route('admin.products.images.primary', [$product, $image]) }}" title="{{ __('تعيين كصورة مصغّرة') }}">@csrf<button class="text-white text-[10px] hover:underline">{{ __('مصغّرة') }}</button></form>
                                        <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $image]) }}" onsubmit="return confirm('{{ __('حذف الصورة؟') }}')">@csrf @method('DELETE')<button class="text-white text-[10px] hover:underline">{{ __('حذف') }}</button></form>
                                    </div>
                                </div>
                            @endforeach

                            <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data">
                                @csrf
                                <input type="file" name="images[]" accept="image/*" multiple class="hidden" id="gallery-add" onchange="this.form.submit()" />
                                <label for="gallery-add" class="flex flex-col items-center justify-center w-24 h-24 rounded-lg border-2 border-dashed border-gray-300 text-gray-400 hover:border-emerald-400 hover:text-emerald-500 cursor-pointer transition">
                                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    <span class="text-[10px] mt-0.5">800 × 800</span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>

                @error('image')<p class="mt-3 text-xs text-rose-600">{{ $message }}</p>@enderror
                @error('images.*')<p class="mt-3 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
        @endif
    </div>

    {{-- مصفوفة المتغيّرات الحيّة: تبني التركيبات من القيم المختارة وتحفظ السعر/الكمية --}}
    <script>
        function variantMatrix(config) {
            return {
                attributes: config.attributes || [],
                defaultPrice: config.defaultPrice || 0,
                originalQty: config.originalQty || 0,
                // مجموع الكميات المُدخلة الآن — يجب أن يساوي العدد الأصلي (توزيع لا إضافة).
                get enteredQty() {
                    return this.rows.reduce((sum, r) => sum + (Number(this.cells[r.key]?.stock) || 0), 0);
                },
                get qtyMatches() {
                    return this.originalQty <= 0 || Math.abs(this.enteredQty - this.originalQty) < 0.001;
                },
                selected: {},
                cells: {},
                rows: [],
                init() {
                    this.attributes.forEach(a => { this.selected[a.id] = []; });
                    (config.existing || []).forEach(ex => {
                        const key = this.sig(ex.values);
                        this.cells[key] = { price: ex.price, stock: ex.stock };
                        ex.values.forEach(vid => {
                            const attr = this.attributes.find(a => a.values.some(v => v.id === vid));
                            if (attr && !this.selected[attr.id].includes(vid)) this.selected[attr.id].push(vid);
                        });
                    });
                    this.build();
                },
                toggle(attrId, valueId) {
                    const arr = this.selected[attrId];
                    const i = arr.indexOf(valueId);
                    if (i >= 0) arr.splice(i, 1); else arr.push(valueId);
                    this.build();
                },
                isSel(attrId, valueId) {
                    return (this.selected[attrId] || []).includes(valueId);
                },
                build() {
                    const groups = this.attributes
                        .map(a => ({ id: a.id, values: this.selected[a.id] || [] }))
                        .filter(g => g.values.length > 0);
                    let combos = groups.length ? [[]] : [];
                    groups.forEach(g => {
                        const next = [];
                        combos.forEach(c => g.values.forEach(vid => next.push([...c, vid])));
                        combos = next;
                    });
                    this.rows = combos.map(vals => {
                        const key = this.sig(vals);
                        if (!this.cells[key]) this.cells[key] = { price: this.defaultPrice, stock: 0 };
                        return { key: key, values: vals, label: this.labelOf(vals) };
                    });
                },
                labelOf(vals) {
                    return vals.map(vid => {
                        for (const a of this.attributes) {
                            const v = a.values.find(x => x.id === vid);
                            if (v) return v.label;
                        }
                        return '';
                    }).join(' / ');
                },
                sig(vals) {
                    return vals.map(Number).sort((a, b) => a - b).join('-');
                },
            };
        }
        document.addEventListener('alpine:init', () => window.Alpine.data('variantMatrix', variantMatrix));
    </script>
</x-app-layout>
