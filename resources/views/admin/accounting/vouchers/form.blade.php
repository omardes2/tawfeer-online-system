<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('accounting.kind.'.$kind) }} — {{ __('جديد') }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ route('admin.accounting.vouchers.store', $kind) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('التاريخ')" name="voucher_date"><input type="date" name="voucher_date" value="{{ old('voucher_date', $voucher->voucher_date) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('المبلغ')" name="amount"><input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الخزينة / البنك')" name="treasury_id">
                        <select name="treasury_id" required class="w-full rounded-md border-gray-300">
                            @foreach ($treasuries as $t)<option value="{{ $t->id }}" @selected(old('treasury_id') == $t->id)>{{ $t->name }} ({{ $t->currency }})</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('accounting.counter_account.'.$kind)" name="counter_account_id">
                        <select name="counter_account_id" required class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— اختر —') }}</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('counter_account_id') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="$kind === 'payment' || $kind === 'expense' ? __('دفعنا إلى') : __('استلمنا من')" name="party_name"><input type="text" name="party_name" value="{{ old('party_name') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('طريقة الدفع')" name="payment_method">
                        <select name="payment_method" class="w-full rounded-md border-gray-300">
                            <option value="">—</option>
                            @foreach (['cash' => 'نقدًا', 'transfer' => 'تحويل', 'card' => 'بطاقة', 'cheque' => 'شيك'] as $v => $l)<option value="{{ $v }}" @selected(old('payment_method')===$v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>

                    @if (in_array($kind, ['receipt', 'income']) && $customers->isNotEmpty())
                        <x-admin.field :label="__('العميل (اختياري)')" name="customer_id"><select name="customer_id" class="w-full rounded-md border-gray-300"><option value="">—</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id')==$c->id)>{{ $c->name }}</option>@endforeach</select></x-admin.field>
                    @endif
                    @if (in_array($kind, ['payment', 'expense']) && $suppliers->isNotEmpty())
                        <x-admin.field :label="__('المورد (اختياري)')" name="supplier_id"><select name="supplier_id" class="w-full rounded-md border-gray-300"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id')==$s->id)>{{ $s->name }}</option>@endforeach</select></x-admin.field>
                    @endif
                    @if (in_array($kind, ['expense', 'income']))
                        <x-admin.field :label="__('الفئة')" name="category"><input type="text" name="category" value="{{ old('category') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    @endif
                    @if ($kind === 'expense')
                        <x-admin.field :label="__('الضريبة (اختياري)')" name="tax_amount"><input type="number" step="0.01" min="0" name="tax_amount" value="{{ old('tax_amount', 0) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    @endif
                    <x-admin.field :label="__('المرجع')" name="reference"><input type="text" name="reference" value="{{ old('reference') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                <x-admin.field :label="__('البيان')" name="description"><input type="text" name="description" value="{{ old('description') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                <x-admin.field :label="__('ملاحظات')" name="notes"><textarea name="notes" rows="2" class="w-full rounded-md border-gray-300">{{ old('notes') }}</textarea></x-admin.field>
                <x-admin.field :label="__('مرفقات (PDF/صور)')" name="attachments"><input type="file" name="attachments[]" multiple accept=".pdf,image/*" class="text-sm" /></x-admin.field>

                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md">{{ __('حفظ كمسودّة') }}</button><a href="{{ route('admin.accounting.vouchers.index', $kind) }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
