<x-app-layout :title="__('المنتجات')">
    <x-admin.header
        :title="__('المنتجات')"
        :description="__('إدارة أصناف الكتالوج وأسعارها ومخزونها وظهورها على الموقع.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الكتالوج') => null, __('المنتجات') => null]">
        @can('create', \App\Modules\Catalog\Models\Product::class)
            <a href="{{ route('admin.products.create') }}" class="btn-primary btn-sm">{{ __('منتج جديد') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    @php $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'); @endphp

    {{-- فلاتر: بحث + الظهور على الموقع + ترتيب --}}
    <form method="GET" action="{{ route('admin.products.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex-1 min-w-[16rem]">
            <label class="block text-xs text-gray-500 mb-1">{{ __('بحث عن صنف') }}</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('الاسم أو SKU…') }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        {{-- الظهور على الموقع بدل حالة التحرير: السؤال العملي هو «ما الذي يراه
             الزبون؟»، وهو العمود نفسه الذي يُبدّل من الجدول أدناه. --}}
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الظهور على الموقع') }}</label>
            <select name="visibility" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm min-w-[10rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('الكل') }}</option>
                @foreach (['visible' => 'ظاهرة على الموقع', 'hidden' => 'مخفية'] as $val => $lbl)
                    <option value="{{ $val }}" @selected(request('visibility') === $val)>{{ __($lbl) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الترتيب') }}</label>
            <select name="sort" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm min-w-[11rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('الافتراضي') }}</option>
                <option value="price_desc" @selected(($activeSort ?? '') === 'price_desc')>{{ __('السعر: الأعلى أولًا') }}</option>
                <option value="price_asc" @selected(($activeSort ?? '') === 'price_asc')>{{ __('السعر: الأقل أولًا') }}</option>
                <option value="qty_desc" @selected(($activeSort ?? '') === 'qty_desc')>{{ __('الكمية: الأعلى أولًا') }}</option>
                <option value="qty_asc" @selected(($activeSort ?? '') === 'qty_asc')>{{ __('الكمية: الأقل أولًا') }}</option>
            </select>
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('تطبيق') }}</button>
        @if (request()->hasAny(['search', 'visibility', 'sort']))
            <a href="{{ route('admin.products.index') }}" class="btn-secondary btn-sm">{{ __('مسح') }}</a>
        @endif
    </form>

    @php $pageIds = $products->pluck('id')->map(fn ($i) => (string) $i)->all(); @endphp

    {{--
        التحديد والحذف الجماعي.

        `selected` مصفوفة معرّفات نصّية (قيم صناديق الاختيار نصوص دائمًا).
        صندوق الرأس يعكس حالة الصفحة الحالية لا الكتالوج كلّه — التحديد لا
        يتجاوز الصفحة المعروضة، والعدّاد يقول ذلك صراحةً.
    --}}
    <div x-data="{
        selected: [],
        pageIds: @js($pageIds),
        confirming: false,
        get allOnPage() { return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id)) },
        toggleAll(checked) {
            this.selected = checked
                ? [...new Set([...this.selected, ...this.pageIds])]
                : this.selected.filter(id => ! this.pageIds.includes(id));
        },
    }">
        {{-- شريط الإجراءات: يظهر عند وجود تحديد فقط فلا يزاحم الجدول --}}
        @can('deleteAny', \App\Modules\Catalog\Models\Product::class)
            <div x-show="selected.length > 0" x-cloak
                 class="flex flex-wrap items-center justify-between gap-3 mb-3 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200">
                <span class="text-sm text-emerald-900">
                    {{ __('المحدَّد') }}: <span class="font-bold tabular-nums" x-text="selected.length"></span>
                </span>
                <div class="flex items-center gap-2">
                    <button type="button" @click="selected = []" class="btn-secondary btn-sm">{{ __('إلغاء التحديد') }}</button>
                    <button type="button" @click="confirming = true" class="btn-danger btn-sm">{{ __('حذف المحدَّد') }}</button>
                </div>
            </div>

            {{-- تأكيد الحذف — بنفس هيئة نافذة التأكيد المفردة في بقيّة اللوحة --}}
            <template x-teleport="body">
                <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
                    <div class="absolute inset-0 bg-slate-900/50" @click="confirming = false"></div>
                    <div class="relative admin-card w-full max-w-md p-6" @keydown.escape.window="confirming = false" x-transition>
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-11 h-11 rounded-full bg-rose-50 text-rose-500 shrink-0">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900">{{ __('حذف الأصناف المحدَّدة') }}</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ __('سيُحذف') }} <span class="font-bold tabular-nums" x-text="selected.length"></span>
                                    {{ __('صنفًا من الكتالوج.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-6 flex items-center justify-end gap-2">
                            <button type="button" @click="confirming = false" class="btn-secondary">{{ __('إلغاء') }}</button>
                            <form method="POST" action="{{ route('admin.products.bulk-destroy') }}">
                                @csrf @method('DELETE')
                                <template x-for="id in selected" :key="id">
                                    <input type="hidden" name="products[]" :value="id" />
                                </template>
                                <button type="submit" class="btn-danger">{{ __('حذف') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        @endcan

        <x-admin.table>
            <thead>
                <tr>
                    <th class="w-8">
                        <input type="checkbox" :checked="allOnPage" @change="toggleAll($event.target.checked)"
                               aria-label="{{ __('تحديد كل أصناف الصفحة') }}"
                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                    </th>
                    <th>{{ __('الصورة') }}</th>
                    <th>{{ __('اسم المنتج') }}</th>
                    <th>{{ __('القسم') }}</th>
                    <th>{{ __('السعر') }}</th>
                    <th>{{ __('المتوفّرة') }}</th>
                    <th>{{ __('المباعة') }}</th>
                    <th>{{ __('إظهار على الموقع') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php
                        $price = $product->defaultVariant?->retail_price ?? $product->retail_price;
                        $available = (float) ($product->stocks_sum_on_hand ?? 0) - (float) ($product->stocks_sum_reserved ?? 0);
                        $sold = (float) ($product->order_items_sum_qty_shipped ?? 0);
                        $shown = $product->visibility === 'visible';
                        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" x-model="selected" value="{{ $product->id }}"
                                   aria-label="{{ __('تحديد') }} {{ $product->name }}"
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                        </td>
                        <td>
                            @if ($product->primaryImage)
                                <img src="{{ $product->primaryImage->url() }}" alt="" class="w-11 h-11 rounded-md object-cover bg-gray-100" />
                            @else
                                <span class="grid place-items-center w-11 h-11 rounded-md bg-gray-100 text-gray-300 text-xs">—</span>
                            @endif
                        </td>
                        <td class="font-medium text-gray-800">
                            {{ $product->name }}
                            <span class="block text-[11px] text-gray-400 font-mono">{{ $product->sku }}</span>
                        </td>
                        <td class="text-gray-500">{{ $product->category?->name ?: '—' }}</td>
                        <td class="tabular-nums whitespace-nowrap">{{ $price !== null ? number_format((float) $price, 2).' '.$sym : '—' }}</td>
                        <td class="tabular-nums">
                            <span @class(['font-medium', 'text-rose-600' => $available <= 0, 'text-gray-800' => $available > 0])>{{ $fmt($available) }}</span>
                        </td>
                        <td class="tabular-nums text-gray-600">{{ $fmt($sold) }}</td>
                        <td>
                            @can('update', $product)
                                <form method="POST" action="{{ route('admin.products.toggle-visibility', $product) }}">
                                    @csrf
                                    <button type="submit" role="switch" aria-checked="{{ $shown ? 'true' : 'false' }}"
                                            class="relative inline-block h-6 w-11 rounded-full transition-colors {{ $shown ? 'bg-emerald-500' : 'bg-gray-300' }}"
                                            title="{{ $shown ? __('ظاهر — اضغط للإخفاء') : __('مخفي — اضغط للإظهار') }}">
                                        <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all {{ $shown ? 'end-0.5' : 'start-0.5' }}"></span>
                                    </button>
                                </form>
                            @else
                                <span class="text-xs {{ $shown ? 'text-emerald-600' : 'text-gray-400' }}">{{ $shown ? __('ظاهر') : __('مخفي') }}</span>
                            @endcan
                        </td>
                        <td class="text-end">
                            <div class="inline-flex items-center gap-1.5">
                                @can('update', $product)
                                    <a href="{{ route('admin.products.edit', $product) }}" class="btn-secondary btn-sm">{{ __('تعديل') }}</a>
                                @endcan
                                @can('delete', $product)
                                    <x-admin.confirm :action="route('admin.products.destroy', $product)" method="DELETE"
                                        :title="__('حذف المنتج')" :message="__('سيُحذف المنتج نهائيًا من الكتالوج.')"
                                        :confirm="__('حذف')" tone="red" :trigger="__('حذف')" />
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="!p-0">
                        <x-admin.empty-state
                            :title="__('لا توجد منتجات')"
                            :description="__('ابدأ بإضافة أول منتج للكتالوج.')"
                            :icon="'M3.75 6h16.5M3.75 12h16.5m-16.5 6h16.5'" />
                    </td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
