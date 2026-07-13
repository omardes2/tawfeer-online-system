<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('تسوية جرد جديدة') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ route('admin.inventory.adjustments.store') }}" class="space-y-5" x-data="{ rows: [{}] }">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-admin.field :label="__('المستودع')" name="warehouse">
                        <select name="warehouse" required class="w-full rounded-md border-gray-300 text-sm">
                            @foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}">{{ $wh->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('النوع')" name="type">
                        <select name="type" class="w-full rounded-md border-gray-300 text-sm">
                            @foreach (['recount' => 'جرد', 'increase' => 'زيادة', 'decrease' => 'نقص'] as $v => $l)<option value="{{ $v }}">{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('السبب')" name="reason"><input type="text" name="reason" class="w-full rounded-md border-gray-300 text-sm" /></x-admin.field>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('البنود (العدّ الفعلي)') }}</label>
                        <button type="button" @click="rows.push({})" class="text-sm text-emerald-600 hover:underline">+ {{ __('إضافة بند') }}</button>
                    </div>
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-end">
                            <select :name="`items[${i}][variant]`" required class="rounded-md border-gray-300 text-sm">
                                @foreach ($products->filter(fn($p) => $p->defaultVariant) as $p)<option value="{{ $p->defaultVariant->uuid }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                            </select>
                            <input type="number" step="0.001" min="0" :name="`items[${i}][qty_counted]`" placeholder="{{ __('العدّ الفعلي') }}" required class="rounded-md border-gray-300 text-sm w-32" />
                            <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                </div>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ كمسودّة') }}</button>
                    <a href="{{ route('admin.inventory.adjustments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
