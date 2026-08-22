<x-app-layout :title="$list->exists ? $list->name : __('قائمة أسعار جديدة')">
    <x-admin.header
        :title="$list->exists ? $list->name : __('قائمة أسعار جديدة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('قوائم أسعار التجّار') => route('admin.price_lists.index'), ($list->exists ? __('تعديل') : __('جديدة')) => null]" />

    <x-admin.flash />

    <div class="admin-card admin-card-pad mb-5">
        <form method="POST" action="{{ $list->exists ? route('admin.price_lists.update', $list) : route('admin.price_lists.store') }}"
              class="grid gap-4 sm:grid-cols-2">
            @csrf
            @if ($list->exists) @method('PUT') @endif

            <x-admin.field :label="__('اسم القائمة')" name="name" :required="true">
                <input type="text" name="name" value="{{ old('name', $list->name) }}" required maxlength="120"
                       placeholder="{{ __('أسعار تجّار') }}"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <x-admin.field :label="__('الرمز (اختياري)')" name="code">
                <input type="text" name="code" value="{{ old('code', $list->code) }}" maxlength="40" dir="ltr"
                       class="w-full rounded-lg border-gray-300 font-mono focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <x-admin.field :label="__('ترث من')" name="parent_id"
                           :hint="__('ما لم يُسعَّر هنا يُقرأ من القائمة المختارة — فتكفي قائمةَ التاجر أصنافُه المختلفة وحدها.')">
                <select name="parent_id" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('— لا ترث —') }}</option>
                    @foreach ($parents as $p)
                        <option value="{{ $p->id }}" @selected((int) old('parent_id', $list->parent_id) === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            <x-admin.field :label="__('ملاحظات')" name="notes">
                <input type="text" name="notes" value="{{ old('notes', $list->notes) }}" maxlength="500"
                       class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
            </x-admin.field>

            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $list->exists ? $list->is_active : true))
                       class="rounded border-gray-300 text-emerald-600" />
                {{ __('نشطة') }}
                <span class="text-xs text-gray-400">{{ __('المعطَّلة يعود أصحابها إلى سعر الجملة.') }}</span>
            </label>

            <div class="sm:col-span-2">
                <button class="btn-primary btn-sm">{{ $list->exists ? __('حفظ') : __('إنشاء') }}</button>
            </div>
        </form>
    </div>

    @if ($list->exists)
        <div class="admin-card admin-card-pad mb-5">
            <h3 class="font-semibold text-gray-800 mb-3">{{ __('إضافة صنف أو تعديل سعره') }}</h3>
            <form method="POST" action="{{ route('admin.price_lists.items.store', $list) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-[16rem]">
                    <label for="pl-variant" class="block text-xs text-gray-500 mb-1">{{ __('الصنف') }}</label>
                    {{-- قائمة بحثٍ أصلية في المتصفّح: الكتالوج طويل، و`datalist` تكفي بلا JS. --}}
                    <select id="pl-variant" name="variant_id" required
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('— اختر الصنف —') }}</option>
                        @foreach ($variants as $v)
                            <option value="{{ $v['id'] }}">
                                {{ $v['label'] }} ({{ $v['sku'] }}) — {{ __('جملة') }} {{ number_format($v['wholesale'], 2) }} · {{ __('بيع') }} {{ number_format($v['retail'], 2) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pl-price" class="block text-xs text-gray-500 mb-1">{{ __('سعر التاجر') }}</label>
                    <input id="pl-price" type="number" step="0.01" min="0" name="price" required
                           class="w-32 rounded-lg border-gray-300 text-sm tabular-nums focus:border-emerald-500 focus:ring-emerald-500" />
                </div>
                <button class="btn-primary btn-sm">{{ __('حفظ') }}</button>
            </form>
        </div>

        <x-admin.table :title="__('أسعار هذه القائمة')" stack>
            <thead>
                <tr>
                    <th>{{ __('الصنف') }}</th>
                    <th class="text-start">{{ __('سعر التاجر') }}</th>
                    <th class="text-start">{{ __('سعر الجملة') }}</th>
                    <th class="text-start">{{ __('سعر البيع') }}</th>
                    <th class="text-center">{{ __('المصدر') }}</th>
                    <th class="text-center">{{ __('إجراء') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php $variant = $item->variant; @endphp
                    <tr>
                        <td data-label="{{ __('الصنف') }}" class="font-medium text-gray-800">
                            {{ $variant?->product?->name ?? __('صنف محذوف') }}
                            @if ($variant && $variant->attributeValues->isNotEmpty())
                                <span class="text-xs text-gray-500">— {{ $variant->optionLabel() }}</span>
                            @endif
                            <span class="block text-[11px] text-gray-400 font-mono">{{ $variant?->sku }}</span>
                        </td>
                        <td data-label="{{ __('سعر التاجر') }}" class="text-start tabular-nums font-semibold">{{ number_format((float) $item->price, 2) }}</td>
                        <td data-label="{{ __('سعر الجملة') }}" class="text-start tabular-nums text-gray-500">{{ number_format($variant?->effectiveWholesalePrice() ?? 0, 2) }}</td>
                        <td data-label="{{ __('سعر البيع') }}" class="text-start tabular-nums text-gray-500">{{ number_format((float) ($variant->retail_price ?? 0), 2) }}</td>
                        <td data-label="{{ __('المصدر') }}" class="text-center">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-emerald-50 text-emerald-700 ring-emerald-200">{{ __('هذه القائمة') }}</span>
                        </td>
                        <td data-label="{{ __('إجراء') }}" class="text-center">
                            <x-admin.confirm
                                :action="route('admin.price_lists.items.destroy', [$list, $item])"
                                :trigger="__('حذف')"
                                :message="__('حذف سعر هذا الصنف؟ يعود إلى سعر القائمة الأب أو سعر الجملة.')" />
                        </td>
                    </tr>
                @empty
                    @if ($inherited->isEmpty())
                        <tr><td colspan="6" class="!p-0">
                            <x-admin.empty-state
                                :title="__('لا أصناف في هذه القائمة')"
                                :description="__('أضِف الأصناف وأسعارها من النموذج أعلاه. وما لا تضعه هنا يُقرأ من القائمة الأب أو سعر الجملة.')" />
                        </td></tr>
                    @endif
                @endforelse

                {{-- الموروث: ما يدفعه التاجر فعلًا وإن لم يُكتب في هذه القائمة. --}}
                @foreach ($inherited as $variantId => $price)
                    @php $variant = $inheritedVariants[$variantId] ?? null; @endphp
                    <tr class="bg-gray-50/60">
                        <td data-label="{{ __('الصنف') }}" class="text-gray-600">
                            {{ $variant?->product?->name ?? __('صنف محذوف') }}
                            @if ($variant && $variant->attributeValues->isNotEmpty())
                                <span class="text-xs text-gray-500">— {{ $variant->optionLabel() }}</span>
                            @endif
                            <span class="block text-[11px] text-gray-400 font-mono">{{ $variant?->sku }}</span>
                        </td>
                        <td data-label="{{ __('سعر التاجر') }}" class="text-start tabular-nums">{{ number_format((float) $price, 2) }}</td>
                        <td data-label="{{ __('سعر الجملة') }}" class="text-start tabular-nums text-gray-500">{{ number_format($variant?->effectiveWholesalePrice() ?? 0, 2) }}</td>
                        <td data-label="{{ __('سعر البيع') }}" class="text-start tabular-nums text-gray-500">{{ number_format((float) ($variant->retail_price ?? 0), 2) }}</td>
                        <td data-label="{{ __('المصدر') }}" class="text-center">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-sky-50 text-sky-700 ring-sky-200">{{ __('موروث') }}</span>
                        </td>
                        <td data-label="{{ __('إجراء') }}" class="text-center text-[11px] text-gray-400">{{ __('عدّله بإضافته أعلاه') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin.table>
    @endif
</x-app-layout>
