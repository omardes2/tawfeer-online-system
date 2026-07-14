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

    {{-- فلاتر: بحث + حالة + ترتيب --}}
    <form method="GET" action="{{ route('admin.products.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex-1 min-w-[16rem]">
            <label class="block text-xs text-gray-500 mb-1">{{ __('بحث عن صنف') }}</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('الاسم أو SKU…') }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الحالة') }}</label>
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm min-w-[9rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (['active' => 'مفعّل', 'draft' => 'مسودّة', 'archived' => 'مؤرشف'] as $val => $lbl)
                    <option value="{{ $val }}" @selected(request('status') === $val)>{{ __($lbl) }}</option>
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
        @if (request()->hasAny(['search', 'status', 'sort']))
            <a href="{{ route('admin.products.index') }}" class="btn-secondary btn-sm">{{ __('مسح') }}</a>
        @endif
    </form>

    <div x-data="{ all: false }">
        <x-admin.table>
            <thead>
                <tr>
                    <th class="w-8"><input type="checkbox" x-model="all" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" /></th>
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
                        <td><input type="checkbox" :checked="all" value="{{ $product->id }}" class="row-check rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" /></td>
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
                            @can('update', $product)
                                <a href="{{ route('admin.products.edit', $product) }}" class="btn-secondary btn-sm">{{ __('تعديل') }}</a>
                            @endcan
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
