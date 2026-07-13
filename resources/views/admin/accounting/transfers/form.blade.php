<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('تحويل جديد') }}</h2></x-slot>
    <div class="py-8 max-w-xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ route('admin.accounting.transfers.store') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('التاريخ')" name="voucher_date"><input type="date" name="voucher_date" value="{{ old('voucher_date', now()->toDateString()) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('المبلغ')" name="amount"><input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('من (المصدر)')" name="treasury_id">
                        <select name="treasury_id" required class="w-full rounded-md border-gray-300">
                            @foreach ($treasuries as $t)<option value="{{ $t->id }}" @selected(old('treasury_id')==$t->id)>{{ $t->name }} ({{ __('accounting.kind.transfer') }}: {{ $t->type }})</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('إلى (الوجهة)')" name="counter_treasury_id">
                        <select name="counter_treasury_id" required class="w-full rounded-md border-gray-300">
                            @foreach ($treasuries as $t)<option value="{{ $t->id }}" @selected(old('counter_treasury_id')==$t->id)>{{ $t->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('المرجع')" name="reference"><input type="text" name="reference" value="{{ old('reference') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('البيان')" name="description"><input type="text" name="description" value="{{ old('description') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>
                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('حفظ كمسودّة') }}</button><a href="{{ route('admin.accounting.transfers.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
