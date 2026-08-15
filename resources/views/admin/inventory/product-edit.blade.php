<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزن') }} — {{ __('تعديل صنف') }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-admin.flash />

        <a href="{{ route('admin.inventory.stocks') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            {{ __('عودة للمخزن') }}
        </a>

        <form method="POST" action="{{ route('admin.inventory.products.update', $product) }}"
              class="bg-white shadow-sm sm:rounded-lg p-6 space-y-5">
            @csrf @method('PUT')

            <div>
                <h3 class="text-lg font-bold text-gray-900">{{ $product->name }}</h3>
                <p class="text-sm text-gray-400">{{ __('تعديل بيانات الصنف الأساسية والأسعار.') }}</p>
            </div>

            <x-admin.field :label="__('كود المنتج')" name="sku" required>
                <input type="text" name="sku" value="{{ old('sku', $product->sku) }}" required
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <x-admin.field :label="__('الفئة')" name="category_id">
                <select name="category_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— بدون فئة —') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-admin.field :label="__('سعر الشراء').' ('.$currency.')'" name="cost_price">
                    <input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price', $product->cost_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('سعر البيع').' ('.$currency.')'" name="retail_price">
                    <input type="number" step="0.01" min="0" name="retail_price" value="{{ old('retail_price', $product->retail_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
                <x-admin.field :label="__('سعر الجملة').' ('.$currency.')'" name="wholesale_price">
                    <input type="number" step="0.01" min="0" name="wholesale_price" value="{{ old('wholesale_price', $product->wholesale_price) }}"
                           class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                </x-admin.field>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <a href="{{ route('admin.inventory.stocks') }}" class="btn-secondary">{{ __('إلغاء') }}</a>
                <button type="submit" class="btn-primary">{{ __('حفظ') }}</button>
            </div>
        </form>

        {{--
            تعديل الكميات — نموذج منفصل عن الأسعار عمدًا: تغييرُ رصيدٍ حركةٌ
            مخزنية لها سبب وأثر، لا حقلٌ يُحفظ مع اسم الصنف.

            صفٌّ لكل متغيّر لأن المخزون يُحفظ لكل متغيّر في كل مستودع على حدة.
        --}}
        @can('inventory.operations.receive')
            <form method="POST" action="{{ route('admin.inventory.products.quantities', $product) }}"
                  class="bg-white shadow-sm sm:rounded-lg p-6 space-y-5">
                @csrf @method('PUT')

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">{{ __('الكميات') }}</h3>
                        <p class="text-sm text-gray-400">
                            {{ __('اكتب الكمية الجديدة؛ يسجّل النظام الفرق كحركة تسوية. اترك الخانة فارغة لتُبقيها كما هي.') }}
                        </p>
                    </div>

                    @if ($warehouses->count() > 1)
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">{{ __('المستودع') }}</label>
                            <select name="warehouse_id" class="rounded-lg border-gray-300 text-sm"
                                    onchange="window.location = '{{ route('admin.inventory.products.edit', $product) }}?warehouse_id=' + this.value">
                                @foreach ($warehouses as $wh)
                                    <option value="{{ $wh->id }}" @selected($currentWarehouse?->id === $wh->id)>{{ $wh->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="warehouse_id" value="{{ $currentWarehouse?->id }}" />
                    @endif
                </div>

                @if ($variantRows->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('لا متغيّرات مفعّلة لهذا الصنف.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 px-3 font-medium text-start">{{ __('المتغيّر') }}</th>
                                    <th class="py-2 px-3 font-medium text-start">{{ __('الكمية الحالية') }}</th>
                                    <th class="py-2 px-3 font-medium text-start">{{ __('الكمية الجديدة') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach ($variantRows as $row)
                                    <tr>
                                        <td class="py-2 px-3">
                                            <span class="text-gray-800">{{ $row['options'] ?: $product->name }}</span>
                                            <span class="block text-xs text-gray-400 font-mono">{{ $row['sku'] }}</span>
                                        </td>
                                        <td class="py-2 px-3 tabular-nums font-bold text-gray-900">
                                            {{ rtrim(rtrim(number_format($row['on_hand'], 3), '0'), '.') }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <input type="number" step="0.001" min="0"
                                                   name="quantities[{{ $row['id'] }}]"
                                                   value="{{ old('quantities.'.$row['id']) }}"
                                                   placeholder="{{ __('بلا تغيير') }}"
                                                   class="w-32 rounded-md border-gray-300 text-sm" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-admin.field :label="__('سبب التعديل')" name="reason">
                        <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255"
                               placeholder="{{ __('جرد، تلف، تصحيح إدخال…') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>

                    <div class="flex items-center justify-end">
                        <button type="submit" class="btn-primary">{{ __('حفظ الكميات') }}</button>
                    </div>
                @endif
            </form>
        @endcan
    </div>
</x-app-layout>
