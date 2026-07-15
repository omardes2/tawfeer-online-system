<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $customer->exists ? __('تعديل عميل') : __('عميل جديد') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            @php
                $phones = old('phones', $customer->relationLoaded('phones') ? $customer->phones->map(fn ($p) => ['phone' => $p->phone, 'label' => $p->label, 'is_primary' => (bool) $p->is_primary])->all() : []);
                $addresses = old('addresses', $customer->relationLoaded('addresses') ? $customer->addresses->map(fn ($a) => ['label' => $a->label, 'address_line' => $a->address_line, 'is_default' => (bool) $a->is_default])->all() : []);
            @endphp
            <form method="POST" action="{{ $customer->exists ? route('admin.crm.customers.update', $customer) : route('admin.crm.customers.store') }}"
                  class="space-y-5" x-data="{ phones: @js(array_values($phones)), addresses: @js(array_values($addresses)) }">
                @csrf
                @if ($customer->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name">
                        <input type="text" name="name" value="{{ old('name', $customer->name) }}" required class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <x-admin.field :label="__('الهاتف الأساسي')" name="primary_phone">
                        <input type="text" name="primary_phone" value="{{ old('primary_phone', $customer->primary_phone) }}" required class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <x-admin.field :label="__('التصنيف')" name="category">
                        <input type="text" name="category" value="{{ old('category', $customer->category) }}" placeholder="{{ __('تجزئة/جملة/VIP') }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                </div>

                <div class="border-t pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('هواتف إضافية') }}</label>
                        <button type="button" @click="phones.push({ phone: '', is_primary: false })" class="text-sm text-emerald-600 hover:underline">+ {{ __('إضافة هاتف') }}</button>
                    </div>
                    <template x-for="(p, i) in phones" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-center">
                            <input type="text" :name="`phones[${i}][phone]`" x-model="p.phone" placeholder="{{ __('الهاتف') }}" class="rounded-md border-gray-300 text-sm" />
                            <input type="text" :name="`phones[${i}][label]`" x-model="p.label" placeholder="{{ __('وصف') }}" class="rounded-md border-gray-300 text-sm" />
                            <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" :name="`phones[${i}][is_primary]`" value="1" x-model="p.is_primary" class="rounded border-gray-300" /> {{ __('أساسي') }}</label>
                            <button type="button" @click="phones.splice(i, 1)" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                </div>

                <div class="border-t pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('العناوين') }}</label>
                        <button type="button" @click="addresses.push({ label: '', address_line: '', is_default: false })" class="text-sm text-emerald-600 hover:underline">+ {{ __('إضافة عنوان') }}</button>
                    </div>
                    <template x-for="(a, i) in addresses" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-center">
                            <input type="text" :name="`addresses[${i}][label]`" x-model="a.label" placeholder="{{ __('وصف') }}" class="rounded-md border-gray-300 text-sm w-28" />
                            <input type="text" :name="`addresses[${i}][address_line]`" x-model="a.address_line" placeholder="{{ __('العنوان') }}" class="rounded-md border-gray-300 text-sm flex-1" />
                            <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" :name="`addresses[${i}][is_default]`" value="1" x-model="a.is_default" class="rounded border-gray-300" /> {{ __('افتراضي') }}</label>
                            <button type="button" @click="addresses.splice(i, 1)" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                </div>

                <div class="flex gap-2 pt-2 border-t">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.crm.customers.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
