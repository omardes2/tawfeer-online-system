<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('أمر شراء جديد') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            @php $variants = $products->filter(fn ($p) => $p->defaultVariant); @endphp
            <form method="POST" action="{{ route('admin.purchasing.orders.store') }}" class="space-y-5" x-data="{ rows: [{ variant: '', qty: 1, cost: 0, discount: 0 }] }">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <x-admin.field :label="__('المورد')" name="supplier">
                        <select name="supplier" required class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            @foreach ($suppliers as $s)<option value="{{ $s->uuid }}" @selected(old('supplier') === $s->uuid)>{{ $s->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('المستودع')" name="warehouse">
                        <select name="warehouse" required class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            @foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}" @selected(old('warehouse') === $wh->uuid)>{{ $wh->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('تاريخ الأمر')" name="order_date">
                        <input type="date" name="order_date" value="{{ old('order_date', now()->toDateString()) }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <x-admin.field :label="__('التاريخ المتوقّع')" name="expected_date">
                        <input type="date" name="expected_date" value="{{ old('expected_date') }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('البنود') }}</label>
                        <button type="button" @click="rows.push({ variant: '', qty: 1, cost: 0, discount: 0 })" class="text-sm text-indigo-600 hover:underline">+ {{ __('إضافة بند') }}</button>
                    </div>
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-end">
                            <select :name="`items[${i}][variant]`" x-model="row.variant" required class="rounded-md border-gray-300 text-sm">
                                <option value="">—</option>
                                @foreach ($variants as $p)<option value="{{ $p->defaultVariant->uuid }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                            </select>
                            <input type="number" step="0.001" min="0.001" :name="`items[${i}][qty_ordered]`" x-model="row.qty" placeholder="{{ __('الكمية') }}" required class="rounded-md border-gray-300 text-sm w-28" />
                            <input type="number" step="0.0001" min="0" :name="`items[${i}][unit_cost]`" x-model="row.cost" placeholder="{{ __('التكلفة') }}" required class="rounded-md border-gray-300 text-sm w-28" />
                            <input type="number" step="0.01" min="0" :name="`items[${i}][discount]`" x-model="row.discount" placeholder="{{ __('خصم') }}" class="rounded-md border-gray-300 text-sm w-24" />
                            <span class="text-xs text-gray-500 self-center" x-text="((row.qty * row.cost) - row.discount).toFixed(2)"></span>
                            <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                </div>

                <x-admin.field :label="__('ملاحظات')" name="notes">
                    <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ old('notes') }}</textarea>
                </x-admin.field>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('حفظ كمسودّة') }}</button>
                    <a href="{{ route('admin.purchasing.orders.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
