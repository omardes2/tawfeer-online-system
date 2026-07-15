<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $treasury->exists ? __('تعديل') : __('إضافة') }} — {{ $isBank ? __('حساب بنكي') : __('خزينة') }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $treasury->exists ? route('admin.accounting.'.$rp.'.update', $treasury) : route('admin.accounting.'.$rp.'.store') }}" class="space-y-4">
                @csrf @if ($treasury->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الرمز')" name="code"><input type="text" name="code" value="{{ old('code', $treasury->code ?: $suggestedCode) }}" required class="w-full rounded-md border-gray-300 font-mono" @if($treasury->exists) readonly @endif /></x-admin.field>
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $treasury->name) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الاسم (إنجليزي)')" name="name_en"><input type="text" name="name_en" value="{{ old('name_en', $treasury->name_en) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('العملة')" name="currency"><input type="text" name="currency" value="{{ old('currency', $treasury->currency ?: 'ILS') }}" maxlength="3" class="w-full rounded-md border-gray-300 uppercase" /></x-admin.field>
                    @unless ($treasury->exists)
                        <x-admin.field :label="__('الرصيد الافتتاحي')" name="opening_balance"><input type="number" step="0.01" min="0" name="opening_balance" value="{{ old('opening_balance', 0) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('حساب GL (اختياري — يُنشأ تلقائيًا)')" name="gl_account_id">
                            <select name="gl_account_id" class="w-full rounded-md border-gray-300">
                                <option value="">{{ __('— إنشاء حساب مخصّص تلقائيًا —') }}</option>
                                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('gl_account_id') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>@endforeach
                            </select>
                        </x-admin.field>
                    @endunless
                    <label class="flex items-center gap-2 text-sm pt-6"><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $treasury->exists ? $treasury->is_active : true)) class="rounded border-gray-300 text-emerald-600" />{{ __('نشط') }}</label>
                </div>

                @if ($isBank)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t pt-4">
                        <x-admin.field :label="__('اسم البنك')" name="bank_name"><input type="text" name="bank_name" value="{{ old('bank_name', $treasury->bank_name) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('اسم الحساب')" name="account_name"><input type="text" name="account_name" value="{{ old('account_name', $treasury->account_name) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field :label="__('رقم الحساب')" name="account_number"><input type="text" name="account_number" value="{{ old('account_number', $treasury->account_number) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                        <x-admin.field label="IBAN" name="iban"><input type="text" name="iban" value="{{ old('iban', $treasury->iban) }}" class="w-full rounded-md border-gray-300 font-mono" /></x-admin.field>
                        <x-admin.field label="SWIFT / BIC" name="swift"><input type="text" name="swift" value="{{ old('swift', $treasury->swift) }}" class="w-full rounded-md border-gray-300 font-mono uppercase" /></x-admin.field>
                    </div>
                @endif

                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ __('حفظ') }}</button><a href="{{ route('admin.accounting.'.$rp.'.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
