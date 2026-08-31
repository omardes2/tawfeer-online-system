<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">
        {{ __('commissions.statement') }} — {{ $earner?->name }} <span class="text-sm text-gray-400">({{ __('commissions.'.$earnerType) }})</span>
    </h2></x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <a href="{{ route('admin.commissions.index') }}" class="text-sm text-gray-500 hover:text-emerald-600">← {{ __('commissions.back_to_people') }}</a>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3">
                <ul class="list-disc pr-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- الرصيد الإجمالي --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-lg font-bold text-gray-900">{{ number_format($balance['earned'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('commissions.earned') }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-lg font-bold text-emerald-700">{{ number_format($balance['paid'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('commissions.total_paid') }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-lg font-bold text-amber-600">{{ number_format($balance['pending_payout'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('commissions.pending_payout') }}</p>
            </div>
            <div class="rounded-lg border-2 border-indigo-200 bg-indigo-50 p-4">
                <p class="text-lg font-bold text-indigo-700">{{ number_format($balance['outstanding'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('commissions.outstanding') }}</p>
            </div>
        </div>

        {{-- فلتر الفترة --}}
        <form method="GET" class="bg-white border border-gray-200 rounded-lg p-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="earner_type" value="{{ $earnerType }}">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.period_to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm">
            </div>
            {{--
                الحالة: «مستحقّة» افتراضًا. الكشف يُقرأ ليُصرَف عليه، و«قيد
                الانتظار» حركةٌ لم يصل مالُها من شركة التوصيل بعد — ظهورُها بين
                المستحقّات يُوهم المراجع بأنها واجبة الدفع. وتبقى في القائمة
                لمن يطلبها بدل أن تُحذف فيبحث عنها صاحبُها فلا يجدها.
            --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.state') }}</label>
                <select name="state" class="rounded-md border-gray-300 text-sm">
                    @foreach ($states as $s)
                        <option value="{{ $s }}" @selected($state === $s)>
                            {{ $s === 'all' ? __('commissions.all_states') : __('commissions.'.$s) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="px-4 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">{{ __('commissions.apply_filter') }}</button>

            {{--
                التصدير يحمل الفترة والحالة المعروضتين نفسيهما، ويُصدّر **كل
                حركاتها** لا الصفحة الظاهرة: الملفّ يُبنى عليه صرفٌ ومراجعة،
                وكشفٌ ناقص أسوأ من لا كشف.
            --}}
            <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}"
               class="px-4 py-2 border border-emerald-600 text-emerald-700 text-sm rounded-md hover:bg-emerald-50">
                {{ __('تصدير Excel') }}
            </a>

            <div class="ms-auto text-sm text-gray-600">
                {{ __('commissions.period_earned') }}: <span class="font-bold text-gray-900">{{ number_format($periodEarned, 2) }}</span>
            </div>
        </form>

        {{-- دفع الأرباح --}}
        @can('commissions.payout')
            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <h3 class="font-semibold text-gray-800 mb-4">{{ __('commissions.pay_profit') }}</h3>
                <form method="POST" action="{{ route('admin.commissions.pay_profit') }}" class="grid gap-4 md:grid-cols-3">
                    @csrf
                    <input type="hidden" name="earner_id" value="{{ $earnerId }}">
                    <input type="hidden" name="earner_type" value="{{ $earnerType }}">
                    <input type="hidden" name="period_start" value="{{ $from }}">
                    <input type="hidden" name="period_end" value="{{ $to }}">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.amount_to_pay') }} *</label>
                        <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', max($balance['outstanding'], 0)) }}" required class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.from_treasury') }} *</label>
                        <select name="treasury_id" required class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">{{ __('commissions.select_treasury') }}</option>
                            @php($banks = $treasuries->where('type', 'bank'))
                            @php($cash = $treasuries->where('type', '!=', 'bank'))
                            @if ($banks->isNotEmpty())
                                <optgroup label="{{ __('commissions.bank_accounts') }}">
                                    @foreach ($banks as $t)
                                        <option value="{{ $t->id }}" @selected(old('treasury_id') == $t->id)>{{ $t->name }}@if($t->bank_name) — {{ $t->bank_name }}@endif @if($t->account_number) ({{ $t->account_number }})@endif</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($cash->isNotEmpty())
                                <optgroup label="{{ __('commissions.cash_boxes') }}">
                                    @foreach ($cash as $t)
                                        <option value="{{ $t->id }}" @selected(old('treasury_id') == $t->id)>{{ $t->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        {{-- حساب المصروف يُحسم تلقائيًا (عمولات 5040) — لا حاجة لاختياره. --}}
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">{{ __('commissions.notes') }}</label>
                        <input type="text" name="notes" value="{{ old('notes') }}" placeholder="{{ __('commissions.notes_placeholder') }}" class="w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div class="md:col-span-3">
                        <button type="submit" class="px-5 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('commissions.pay') }}</button>
                        <span class="text-xs text-gray-400 ms-2">{{ __('commissions.pay_hint') }}</span>
                    </div>
                </form>
            </div>
        @endcan

        {{-- أرشيف الدفعات --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('commissions.payments_archive') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.payment_date') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.amount') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.treasury') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.period') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.voucher') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.voucher_status') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('ملاحظات') }}</th>
                        <th class="py-2 px-3 font-medium"></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($payouts as $p)
                            <tr class="border-b">
                                {{--
                                    التاريخ والمبلغ من **السند** لا من نسخته في
                                    الدفعة: السند وثيقةٌ تُعدَّل (عكسٌ ثم قيد
                                    مُصحّح)، والنسخة تبقى على قيمة الإنشاء. فيقول
                                    الدفتر رقمًا ويقول الأرشيف رقمًا آخر، ولا
                                    يظهر ذلك خطأً بل رصيدًا كاذبًا.
                                --}}
                                <td class="py-2 px-3">{{ ($p->voucher?->voucher_date ?? $p->created_at)?->format('Y-m-d') }}</td>
                                <td class="py-2 px-3 font-medium">{{ number_format($p->settledAmount(), 2) }}</td>
                                <td class="py-2 px-3">{{ $p->voucher?->treasury?->name ?? $p->treasury?->name ?? '—' }}</td>
                                <td class="py-2 px-3 text-gray-500">{{ $p->period_start ? $p->period_start->format('Y-m-d').' → '.$p->period_end?->format('Y-m-d') : '—' }}</td>
                                <td class="py-2 px-3">{{ $p->voucher?->number ?? '—' }}</td>
                                <td class="py-2 px-3">
                                    @if ($p->voucher)
                                        <span @class([
                                            'px-2 py-0.5 rounded text-xs',
                                            'bg-emerald-100 text-emerald-700' => $p->voucher->status === 'posted',
                                            'bg-amber-100 text-amber-700' => in_array($p->voucher->status, ['draft', 'approved']),
                                            'bg-gray-100 text-gray-600' => in_array($p->voucher->status, ['cancelled', 'rejected', 'reversed']),
                                        ])>{{ $p->voucher->status }}</span>
                                    @else — @endif
                                </td>
                                {{--
                                    ملاحظة الدفعة: ما كتبه المُصدِر لحظة الصرف —
                                    «تسوية شهر آب» أو «خصم سلفة». تُقرأ هنا بلا
                                    فتح السند، وتُقتطع بـ`truncate` فلا تكسر الجدول.
                                --}}
                                <td class="py-2 px-3 text-gray-600 max-w-xs">
                                    @php($note = $p->notes ?: $p->voucher?->notes)
                                    @if ($note)
                                        <span class="block truncate" title="{{ $note }}">{{ $note }}</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    {{--
                                        السند يُمرَّر نموذجًا لا رقمًا: مفتاح مساره
                                        `uuid` لا `id` (HasUuid)، فتمريرُ الرقم كان
                                        يبحث عن سندٍ uuid‑ه «8» فلا يجده — رابطٌ
                                        يفتح صفحة «غير موجود».
                                    --}}
                                    @if ($p->voucher)
                                        <a href="{{ route('admin.accounting.vouchers.show', ['kind' => $p->voucher->kind, 'voucher' => $p->voucher]) }}" class="text-emerald-600 hover:underline">{{ __('commissions.view_voucher') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-gray-400">{{ __('commissions.no_payments') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- حركات المستحقّات ضمن الفترة --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right admin-table-stack">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.order') }}</th>
                        {{--
                            رقم التتبّع بجانب الطلب: به يُطابَق السطر مع كشف شركة
                            التوصيل عند المراجعة. يُقرأ عرضًا فقط — Protected
                            Delivery Integration — Do Not Modify.
                        --}}
                        <th class="py-2 px-3 font-medium">{{ __('رقم التتبّع') }}</th>
                        {{--
                            الصنف: الحركة لكل **بند** لا لكل طلب، فالطلب ذو
                            الصنفين يعطي سطرين برقمٍ واحد. وبلا هذا العمود
                            يبدوان تكرارًا وهما بندان مختلفان.
                        --}}
                        <th class="py-2 px-3 font-medium">{{ __('الصنف') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.order_date') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.entry_type') }}</th>
                        {{--
                            الأعمدة الثلاثة تُقرأ طرحًا: سعر المنتج − سعر الجملة
                            = الربح. وكلاهما **قيمة السطر** (مضروبةً في الكمية)
                            لا سعر الوحدة — وإلّا لم يصحّ الطرح على بندٍ كميّتُه
                            أكثر من واحد.
                        --}}
                        <th class="py-2 px-3 font-medium">{{ __('commissions.sale_price') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.buy_price') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.profit') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('commissions.state') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($entries as $e)
                            <tr class="border-b">
                                <td class="py-2 px-3" data-label="{{ __('commissions.order') }}">
                                    @if ($e->order)
                                        {{-- رقم الطلب يفتح فاتورته في تبويب جديد --}}
                                        <a href="{{ route('admin.sales.orders.invoice', $e->order) }}" target="_blank"
                                           class="text-emerald-600 hover:underline font-medium">{{ $e->order->number }}</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-500 font-mono text-xs" data-label="{{ __('رقم التتبّع') }}">
                                    {{ $e->order?->tracking_number ?: '—' }}
                                </td>
                                <td class="py-2 px-3 text-gray-600" data-label="{{ __('الصنف') }}">
                                    @if ($e->variant)
                                        {{ $e->variant->product?->name ?? $e->variant->sku }}
                                        @if ($e->variant->attributeValues->isNotEmpty())
                                            <span class="text-xs text-gray-400">— {{ $e->variant->optionLabel() }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-500 whitespace-nowrap" data-label="{{ __('commissions.order_date') }}">{{ $e->order?->created_at?->format('Y-m-d') ?? '—' }}</td>
                                <td class="py-2 px-3" data-label="{{ __('commissions.entry_type') }}">{{ __('commissions.'.$e->entry_type) }}</td>
                                @php($sale = $e->saleValue())
                                @php($cost = $e->costValue())
                                <td class="py-2 px-3 tabular-nums" data-label="{{ __('commissions.sale_price') }}">
                                    {{ $sale === null ? '—' : number_format($sale, 2) }}
                                </td>
                                <td class="py-2 px-3 tabular-nums text-gray-500" data-label="{{ __('commissions.buy_price') }}">
                                    {{ $cost === null ? '—' : number_format($cost, 2) }}
                                </td>
                                <td class="py-2 px-3 tabular-nums font-medium {{ (float) $e->amount < 0 ? 'text-rose-600' : '' }}" data-label="{{ __('commissions.profit') }}">{{ number_format((float) $e->amount, 2) }}</td>
                                <td class="py-2 px-3" data-label="{{ __('commissions.state') }}">{{ __('commissions.'.$e->state) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-gray-400">{{ __('لا توجد حركات بهذه الحالة في الفترة.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        </div>
    </div>
</x-app-layout>
