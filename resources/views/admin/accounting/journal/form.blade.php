<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('قيد يومية جديد') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ route('admin.accounting.journal.store') }}" class="space-y-5"
                  x-data="{ rows: [{ account: '', debit: 0, credit: 0 }, { account: '', debit: 0, credit: 0 }],
                            get totalDebit() { return this.rows.reduce((s, r) => s + (parseFloat(r.debit) || 0), 0) },
                            get totalCredit() { return this.rows.reduce((s, r) => s + (parseFloat(r.credit) || 0), 0) },
                            get balanced() { return this.totalDebit > 0 && Math.abs(this.totalDebit - this.totalCredit) < 0.001 } }">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('التاريخ')" name="entry_date">
                        <input type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                    <x-admin.field :label="__('البيان')" name="description">
                        <input type="text" name="description" value="{{ old('description') }}" class="w-full rounded-md border-gray-300 text-sm" />
                    </x-admin.field>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">{{ __('السطور') }}</label>
                        <button type="button" @click="rows.push({ account: '', debit: 0, credit: 0 })" class="text-sm text-emerald-600 hover:underline">+ {{ __('إضافة سطر') }}</button>
                    </div>
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="flex flex-wrap gap-2 mb-2 items-end">
                            <select :name="`lines[${i}][account_code]`" x-model="row.account" required class="rounded-md border-gray-300 text-sm">
                                <option value="">—</option>
                                @foreach ($accounts as $a)<option value="{{ $a->code }}">{{ $a->code }} — {{ $a->name }}</option>@endforeach
                            </select>
                            <input type="number" step="0.01" min="0" :name="`lines[${i}][debit]`" x-model="row.debit" placeholder="{{ __('مدين') }}" class="rounded-md border-gray-300 text-sm w-28" />
                            <input type="number" step="0.01" min="0" :name="`lines[${i}][credit]`" x-model="row.credit" placeholder="{{ __('دائن') }}" class="rounded-md border-gray-300 text-sm w-28" />
                            <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 2" class="text-rose-500 text-sm">&times;</button>
                        </div>
                    </template>
                    <div class="flex gap-6 text-sm mt-2 border-t pt-2">
                        <span>{{ __('إجمالي المدين') }}: <b x-text="totalDebit.toFixed(2)"></b></span>
                        <span>{{ __('إجمالي الدائن') }}: <b x-text="totalCredit.toFixed(2)"></b></span>
                        <span :class="balanced ? 'text-emerald-600' : 'text-rose-600'" x-text="balanced ? '{{ __('متوازن') }}' : '{{ __('غير متوازن') }}'"></span>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="post" value="1" class="rounded border-gray-300" @cannot('accounting.journal.post') disabled @endcannot />
                    {{ __('ترحيل فوري') }} @cannot('accounting.journal.post') <span class="text-gray-400 text-xs">({{ __('لا صلاحية') }})</span> @endcannot
                </label>

                <div class="flex gap-2 pt-2">
                    <button :disabled="!balanced" :class="balanced ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-gray-300 cursor-not-allowed'" class="px-4 py-2 text-white text-sm rounded-md">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.accounting.journal.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
