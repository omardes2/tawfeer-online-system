<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('accounting.kind.'.$kind) }} — {{ $voucher->exists ? __('تعديل') : __('جديد') }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            @if ($voucher->exists && $voucher->status === 'posted')
                <p class="mb-4 text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2">{{ __('هذا السند مُرحّل — حفظ التعديل يعكس القيد الأصلي ويُرحّل قيدًا مُصحّحًا بالقيم الجديدة.') }}</p>
            @endif
            <form method="POST" action="{{ $voucher->exists ? route('admin.accounting.vouchers.update', [$kind, $voucher]) : route('admin.accounting.vouchers.store', $kind) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @if ($voucher->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('التاريخ')" name="voucher_date"><input type="date" name="voucher_date" value="{{ old('voucher_date', $voucher->voucher_date?->toDateString() ?? $voucher->voucher_date) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('المبلغ')" name="amount"><input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $voucher->amount) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الخزينة / البنك')" name="treasury_id">
                        <select name="treasury_id" required class="w-full rounded-md border-gray-300">
                            @foreach ($treasuries as $t)<option value="{{ $t->id }}" @selected(old('treasury_id', $voucher->treasury_id) == $t->id)>{{ $t->name }} ({{ $t->currency }})</option>@endforeach
                        </select>
                    </x-admin.field>
                    @if ($kind === 'expense')
                        {{--
                            المصروف يختار **تصنيفًا** لا حسابًا: الحساب يُشتقّ منه في
                            المتحكّم فيبقى القيد كما كان، ويكفي المستخدمَ أن يعرف
                            «عمال تنزيل» دون أن يعرف رمزًا في الدليل.
                        --}}
                        <x-admin.field :label="__('تصنيف المصروف')" name="expense_category_id" :required="! $voucher->exists">
                            {{--
                                تحذيرٌ لحظة الاختيار: تصنيفٌ تحتسبه الميزانية من
                                مصدره (الإعلانات من جدولها، العمولات من دفترها)
                                يُعرَض سندُه ولا يُجمَع — وإلا عُدّ الرقم مرّتين.
                                يُقال هنا لا في التقرير: بعد الحفظ يكون المستخدم
                                قد ظنّ أنه سجّل مصروفًا جديدًا.
                            --}}
                            @php
                                $autoSources = $categories->filter->isAutoCounted()
                                    ->mapWithKeys(fn ($c) => [$c->id => $c->autoSourceLabel()]);
                            @endphp
                            <div x-data="{
                                    open: false, name: '', saving: false, error: '',
                                    autoSources: @js($autoSources),
                                    get autoNotice() { return this.autoSources[this.picked] || ''; },
                                    picked: '{{ old('expense_category_id', $voucher->expense_category_id) }}',
                                    async save() {
                                        const name = this.name.trim();
                                        if (! name || this.saving) return;
                                        this.saving = true; this.error = '';
                                        try {
                                            const res = await fetch('{{ route('admin.accounting.expense_categories.store') }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'Accept': 'application/json',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']').content,
                                                },
                                                body: JSON.stringify({ name }),
                                            });
                                            const data = await res.json().catch(() => ({}));
                                            if (! res.ok) { this.error = data.message || '{{ __('تعذّر إنشاء التصنيف.') }}'; return; }
                                            const label = data.account_code ? data.name + ' (' + data.account_code + ')' : data.name;
                                            this.$refs.picker.add(new Option(label, data.id, true, true));
                                            this.open = false; this.name = '';
                                        } catch (e) {
                                            this.error = '{{ __('تعذّر الاتصال بالخادم.') }}';
                                        } finally { this.saving = false; }
                                    },
                                 }">
                                <div class="flex gap-2">
                                    <select name="expense_category_id" x-ref="picker" x-model="picked" @if (! $voucher->exists) required @endif class="w-full rounded-md border-gray-300">
                                        <option value="">{{ __('— اختر —') }}</option>
                                        @foreach ($categories as $c)
                                            <option value="{{ $c->id }}" @selected(old('expense_category_id', $voucher->expense_category_id) == $c->id)>{{ $c->name }}@if ($c->account) ({{ $c->account->code }})@endif</option>
                                        @endforeach
                                    </select>
                                    @can('create', App\Modules\Accounting\Models\ExpenseCategory::class)
                                        <button type="button" @click="open = true" class="shrink-0 px-3 rounded-md bg-emerald-50 text-emerald-700 text-sm font-medium hover:bg-emerald-100">+ {{ __('تصنيف') }}</button>
                                    @endcan
                                </div>

                                <div x-show="autoNotice" x-cloak class="mt-1.5 rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                                    <span class="font-semibold">{{ __('هذا التصنيف محتسَب آليًّا في الميزانية') }}</span>
                                    — <span>{{ __('من') }} <span x-text="autoNotice"></span>.</span>
                                    <span class="block mt-0.5 text-amber-700">{{ __('سجّل السند إن أردت تتبّع النقد؛ لن يُجمَع في «إجمالي المصاريف» حتى لا يُعدّ الرقم مرّتين.') }}</span>
                                </div>

                                @if ($voucher->exists && ! $voucher->expense_category_id && $voucher->counterAccount)
                                    <p class="mt-1 text-xs text-amber-600">{{ __('هذا السند مربوط بالحساب :a مباشرةً (قبل التصنيفات). اتركه فارغًا ليبقى كما هو.', ['a' => $voucher->counterAccount->code.' — '.$voucher->counterAccount->name]) }}</p>
                                @endif

                                {{-- نافذةٌ صغيرة لا صفحة: مغادرة السند تُفقد ما أُدخل فيه. --}}
                                <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="open = false">
                                    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-5 space-y-3" @click.outside="open = false">
                                        <h3 class="font-semibold text-gray-800">{{ __('تصنيف مصروف جديد') }}</h3>
                                        <p class="text-xs text-gray-500">{{ __('يُفتح له حساب تلقائيًا تحت «مصاريف تشغيلية» باسمه.') }}</p>
                                        <input type="text" x-model="name" maxlength="120" @keydown.enter.prevent="save()"
                                               placeholder="{{ __('مثال: عمال تنزيل') }}" class="w-full rounded-md border-gray-300" />
                                        <p x-show="error" x-text="error" class="text-xs text-rose-600"></p>
                                        <div class="flex gap-2">
                                            <button type="button" @click="save()" :disabled="saving" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md disabled:opacity-50">{{ __('حفظ') }}</button>
                                            <button type="button" @click="open = false" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </x-admin.field>
                    @else
                        <x-admin.field :label="__('accounting.counter_account.'.$kind)" name="counter_account_id">
                            <select name="counter_account_id" required class="w-full rounded-md border-gray-300">
                                <option value="">{{ __('— اختر —') }}</option>
                                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('counter_account_id', $voucher->counter_account_id) == $a->id)>{{ $a->code }} — {{ $a->name }}</option>@endforeach
                            </select>
                        </x-admin.field>
                    @endif
                    <x-admin.field :label="$kind === 'payment' || $kind === 'expense' ? __('دفعنا إلى') : __('استلمنا من')" name="party_name"><input type="text" name="party_name" value="{{ old('party_name', $voucher->party_name) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('طريقة الدفع')" name="payment_method">
                        <select name="payment_method" class="w-full rounded-md border-gray-300">
                            <option value="">—</option>
                            @foreach (['cash' => 'نقدًا', 'transfer' => 'تحويل', 'card' => 'بطاقة', 'cheque' => 'شيك'] as $v => $l)<option value="{{ $v }}" @selected(old('payment_method', $voucher->payment_method)===$v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </x-admin.field>

                    @if (in_array($kind, ['receipt', 'income']) && $customers->isNotEmpty())
                        <x-admin.field :label="__('العميل (اختياري)')" name="customer_id"><select name="customer_id" class="w-full rounded-md border-gray-300"><option value="">—</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $voucher->customer_id)==$c->id)>{{ $c->name }}</option>@endforeach</select></x-admin.field>
                    @endif
                    @if (in_array($kind, ['payment', 'expense']) && $suppliers->isNotEmpty())
                        <x-admin.field :label="__('المورد (اختياري)')" name="supplier_id"><select name="supplier_id" class="w-full rounded-md border-gray-300"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $voucher->supplier_id)==$s->id)>{{ $s->name }}</option>@endforeach</select></x-admin.field>
                    @endif
                    {{-- المصروف صار له تصنيفٌ مُعرَّف بحسابه؛ حقلُ فئةٍ نصّيٍّ إلى جانبه حقلان لمعنًى واحد. --}}
                    @if ($kind === 'income')
                        <x-admin.field :label="__('الفئة')" name="category"><input type="text" name="category" value="{{ old('category', $voucher->category) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    @endif
                    @if ($kind === 'expense')
                        <x-admin.field :label="__('الضريبة (اختياري)')" name="tax_amount"><input type="number" step="0.01" min="0" name="tax_amount" value="{{ old('tax_amount', $voucher->tax_amount ?? 0) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    @endif
                    <x-admin.field :label="__('المرجع')" name="reference"><input type="text" name="reference" value="{{ old('reference', $voucher->reference) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                </div>

                <x-admin.field :label="__('البيان')" name="description"><input type="text" name="description" value="{{ old('description', $voucher->description) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                <x-admin.field :label="__('ملاحظات')" name="notes"><textarea name="notes" rows="2" class="w-full rounded-md border-gray-300">{{ old('notes', $voucher->notes) }}</textarea></x-admin.field>
                <x-admin.field :label="__('مرفقات (PDF/صور)')" name="attachments"><input type="file" name="attachments[]" multiple accept=".pdf,image/*" class="text-sm" /></x-admin.field>

                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <div class="flex gap-2 pt-2"><button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md">{{ $voucher->exists ? __('حفظ التعديل') : __('حفظ كمسودّة') }}</button><a href="{{ $voucher->exists ? route('admin.accounting.vouchers.show', [$kind, $voucher]) : route('admin.accounting.vouchers.index', $kind) }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a></div>
            </form>
        </div>
    </div>
</x-app-layout>
