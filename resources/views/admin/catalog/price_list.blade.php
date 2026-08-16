<x-app-layout :title="__('الأصناف والأسعار')">
    <x-admin.header
        :title="__('الأصناف والأسعار')"
        :description="__('قائمة أسعار للاطّلاع: سعر البيع وسعر الجملة لكل صنف.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الأصناف والأسعار') => null]" />

    @php
        $sym = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪');

        /**
         * السعر كما يُقرأ: رقمٌ واحد إن اتّفقت المقاسات، ومدًى إن اختلفت.
         * الرجوع لسعر المنتج نفسه حين لا يحمله أيٌّ من متغيّراته.
         */
        $priceCell = function ($min, $max, $fallback) use ($sym) {
            $min ??= $fallback;
            $max ??= $fallback;

            if ($min === null) {
                return '—';
            }

            return (float) $min === (float) $max
                ? number_format((float) $min, 2).' '.$sym
                : number_format((float) $min, 2).' – '.number_format((float) $max, 2).' '.$sym;
        };
    @endphp

    {{-- الفلاتر: الفئة أساسًا (هي ما طُلب)، والبحث معها لأن القائمة طويلة --}}
    <form method="GET" action="{{ route('admin.price_list') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex-1 min-w-[16rem]">
            <label class="block text-xs text-gray-500 mb-1">{{ __('بحث عن صنف') }}</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('الاسم أو SKU…') }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الفئة') }}</label>
            <select name="category" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm min-w-[12rem] focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('كل الفئات') }}</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" @selected($activeCategory === $cat->id)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-secondary btn-sm">{{ __('تطبيق') }}</button>
        @if (request()->hasAny(['search', 'category']))
            <a href="{{ route('admin.price_list') }}" class="btn-secondary btn-sm">{{ __('مسح') }}</a>
        @endif
    </form>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('الصنف') }}</th>
                <th>{{ __('الفئة') }}</th>
                <th class="text-start">{{ __('سعر البيع') }}</th>
                <th class="text-start">{{ __('سعر الجملة') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td class="font-medium text-gray-800">
                        {{ $product->name }}
                        <span class="block text-[11px] text-gray-400 font-mono">{{ $product->sku }}</span>
                    </td>
                    <td class="text-gray-500">{{ $product->category?->name ?: '—' }}</td>
                    <td class="text-start tabular-nums whitespace-nowrap font-medium text-gray-800">
                        {{ $priceCell($product->variants_min_retail_price, $product->variants_max_retail_price, $product->retail_price) }}
                    </td>
                    <td class="text-start tabular-nums whitespace-nowrap text-gray-600">
                        {{ $priceCell($product->variants_min_wholesale_price, $product->variants_max_wholesale_price, $product->wholesale_price) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا توجد أصناف')"
                        :description="request()->hasAny(['search', 'category'])
                            ? __('لا صنف يطابق الفلتر — جرّب فئة أخرى أو امسح البحث.')
                            : __('لم تُسجّل أصناف في الكتالوج بعد.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    @if ($products->hasPages())
        <div class="mt-4">{{ $products->links() }}</div>
    @endif

    <p class="mt-4 text-xs text-gray-500">
        {{ __('الصنف الذي تختلف أسعار مقاساته يظهر بمدًى (من – إلى).') }}
    </p>
</x-app-layout>
