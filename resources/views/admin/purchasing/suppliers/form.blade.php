<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $supplier->exists ? __('تعديل مورد') : __('مورد جديد') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            @php
                $existingContacts = old('contacts', $supplier->relationLoaded('contacts')
                    ? $supplier->contacts->map(fn ($c) => ['name' => $c->name, 'position' => $c->position, 'email' => $c->email, 'phone' => $c->phone, 'is_primary' => (bool) $c->is_primary])->all()
                    : []);
            @endphp
            <form method="POST" action="{{ $supplier->exists ? route('admin.purchasing.suppliers.update', $supplier) : route('admin.purchasing.suppliers.store') }}"
                  class="space-y-5" x-data="{ contacts: @js(array_values($existingContacts)) }">
                @csrf
                @if ($supplier->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الرمز')" name="code">
                        {{-- رمز تسلسلي تلقائي يبدأ من 1000 — للعرض فقط (يُولّد عند الحفظ). --}}
                        <input type="text" value="{{ old('code', $supplier->code ?: ($suggestedCode ?? '')) }}" readonly
                               class="w-full rounded-md border-gray-200 bg-gray-50 text-gray-500 text-sm" />
                        @unless ($supplier->exists)
                            <p class="mt-1 text-xs text-gray-400">{{ __('يُنشأ تلقائيًا بالتسلسل عند الحفظ.') }}</p>
                        @endunless
                    </x-admin.field>
                    <x-admin.field :label="__('الاسم')" name="name">
                        <input type="text" name="name" value="{{ old('name', $supplier->name) }}" required class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <x-admin.field :label="__('الهاتف')" name="phone">
                        <input type="text" name="phone" value="{{ old('phone', $supplier->phone) }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                </div>

                <x-admin.field :label="__('العنوان')" name="address">
                    <textarea name="address" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ old('address', $supplier->address) }}</textarea>
                </x-admin.field>

                {{--
                    رصيدٌ افتتاحي يُنشئ قيدًا في اليومية، فهو لمن يملك صلاحية
                    القيود وحده — لا لكل من يُضيف موردًا.
                --}}
                @can('accounting.journal.create')
                    <x-admin.field :label="__('رصيد افتتاحي')" name="opening_balance"
                                   :hint="$supplier->exists
                                       ? __('ما علينا للمورد قبل دخوله النظام. موجب = نحن مدينون له، سالب = دفعنا مقدَّمًا. تغييره يعكس قيده الأصلي ويُرحّل مصحَّحًا.')
                                       : __('ما علينا للمورد قبل دخوله النظام. موجب = نحن مدينون له، سالب = دفعنا مقدَّمًا. يُرحَّل قيدًا: مدين رأس المال / دائن ذمّة المورد.')">
                        <div class="relative">
                            <input type="number" step="0.01" name="opening_balance"
                                   value="{{ old('opening_balance', $supplier->exists ? (float) $supplier->opening_balance : null) }}"
                                   placeholder="0.00" class="w-full rounded-md border-gray-300 text-sm pe-10" />
                            <span class="absolute inset-y-0 end-0 flex items-center px-3 text-xs text-gray-400">₪</span>
                        </div>
                    </x-admin.field>

                    @if ($supplier->hasUnpostedOpening())
                        <p class="text-xs text-amber-600 -mt-2">
                            {{ __('لهذا المورد رصيد افتتاحي مكتوب بلا قيد في الدفاتر (من قبل ترحيل الأرصدة). احفظ لتُرحَّل قيمته.') }}
                        </p>
                    @endif
                @endcan

                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->exists ? $supplier->is_active : true)) class="rounded border-gray-300" />
                    {{ __('نشط') }}
                </label>

                <div class="border-t pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('جهات الاتصال') }}</label>
                        <button type="button" @click="contacts.push({ is_primary: false })" class="text-sm text-emerald-600 hover:underline">+ {{ __('إضافة جهة') }}</button>
                    </div>
                    <template x-for="(c, i) in contacts" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-center">
                            <input type="text" :name="`contacts[${i}][name]`" x-model="c.name" placeholder="{{ __('الاسم') }}" class="rounded-md border-gray-300 text-sm" />
                            <input type="text" :name="`contacts[${i}][position]`" x-model="c.position" placeholder="{{ __('المنصب') }}" class="rounded-md border-gray-300 text-sm" />
                            <input type="text" :name="`contacts[${i}][phone]`" x-model="c.phone" placeholder="{{ __('الهاتف') }}" class="rounded-md border-gray-300 text-sm" />
                            <input type="email" :name="`contacts[${i}][email]`" x-model="c.email" placeholder="{{ __('البريد') }}" class="rounded-md border-gray-300 text-sm" />
                            <label class="inline-flex items-center gap-1 text-xs">
                                <input type="checkbox" :name="`contacts[${i}][is_primary]`" value="1" x-model="c.is_primary" class="rounded border-gray-300" /> {{ __('أساسية') }}
                            </label>
                            <button type="button" @click="contacts.splice(i, 1)" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                </div>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.purchasing.suppliers.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
