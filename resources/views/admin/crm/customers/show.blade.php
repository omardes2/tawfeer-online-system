<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('عميل') }} — {{ $customer->name }}</h2></x-slot>

    @php($currency = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪'))

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4" x-data="{ tab: 'orders' }">
        <x-admin.flash />

        {{-- شريط علوي: عودة + إجراءات --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('admin.crm.customers.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('عودة للعملاء') }}
            </a>
            <div class="flex items-center gap-2">
                @can('update', $customer)
                    <a href="{{ route('admin.crm.customers.edit', $customer) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50">{{ __('تعديل') }}</a>
                @endcan

                @can('delete', $customer)
                    {{--
                        الحذف للمكرّر أو المُدخَل خطأً. نصُّ التأكيد يذكر عكسَ الرصيد
                        الافتتاحي صراحةً: إخفاءُ فعلٍ محاسبي خلف زرّ «حذف» أسوأ من
                        عدم وجود الزرّ.
                    --}}
                    <form method="POST" action="{{ route('admin.crm.customers.destroy', $customer) }}"
                          onsubmit="return confirm(@js($customer->opening_entry_id
                              ? __('سيُحذف العميل، ويُعكس رصيده الافتتاحي (:n) بقيد عاكس، ويُعطَّل حسابه المحاسبي. متابعة؟', ['n' => number_format((float) $customer->opening_balance, 2)])
                              : __('حذف العميل؟ يُرفض إن كانت له طلبات أو حركة على حسابه المحاسبي.')))">
                        @csrf @method('DELETE')
                        <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-rose-200 text-rose-600 text-sm rounded-lg hover:bg-rose-50">{{ __('حذف') }}</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- بطاقة رأس العميل + ملخص الأرصدة --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-col lg:flex-row lg:items-center gap-6">
                <div class="flex items-center gap-4 flex-1">
                    <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-50 text-emerald-600 text-xl font-bold shrink-0">{{ mb_substr($customer->name, 0, 1) }}</span>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-xl font-bold text-gray-900">{{ $customer->name }}</h3>
                            @if ($customer->is_blocked)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">{{ __('محظور') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">{{ __('نشط') }}</span>
                            @endif
                        </div>
                        <div class="text-sm text-gray-500 mt-0.5 space-y-0.5">
                            <div>{{ __('الهاتف') }}: <span class="text-gray-700" dir="ltr">{{ $customer->primary_phone ?: '—' }}</span></div>
                            @if ($customer->email)<div>{{ __('البريد') }}: <span class="text-gray-700">{{ $customer->email }}</span></div>@endif
                            @if ($customer->category)<div>{{ __('التصنيف') }}: <span class="text-gray-700">{{ $customer->category }}</span></div>@endif
                            @if ($customer->glAccount)<div>{{ __('الحساب المحاسبي') }}: <span class="text-gray-700 font-mono">{{ $customer->glAccount->code }}</span> <span class="text-gray-400">— {{ $customer->glAccount->name }}</span></div>@endif
                        </div>
                    </div>
                </div>

                {{-- بطاقات الملخص --}}
                <div class="grid grid-cols-3 gap-3 lg:w-[420px]">
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('مبيعات على الحساب') }}</div>
                        <div class="text-base font-bold text-gray-900 tabular-nums mt-1">{{ number_format($sales, 2) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ __('المقبوضات') }}</div>
                        <div class="text-base font-bold text-emerald-600 tabular-nums mt-1">{{ number_format($received, 2) }}</div>
                    </div>
                    <div class="rounded-lg p-3 text-center {{ abs($balance) < 0.01 ? 'bg-gray-50' : 'bg-rose-50' }}">
                        <div class="text-xs text-gray-500">{{ __('الرصيد المتبقّي') }}</div>
                        <div class="text-base font-bold tabular-nums mt-1 {{ abs($balance) < 0.01 ? 'text-gray-900' : 'text-rose-600' }}">{{ number_format($balance, 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- التبويبات --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center gap-1 border-b border-gray-100 px-4 pt-3">
                @php($tabs = ['orders' => __('الطلبات'), 'receipts' => __('الدفعات'), 'statement' => __('كشف الحساب'), 'details' => __('التفاصيل')])
                @foreach ($tabs as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition">{{ $label }}</button>
                @endforeach
            </div>

            {{-- تبويب الطلبات --}}
            <div x-show="tab === 'orders'" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('رقم الطلب') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الإجمالي') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $o)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4"><a href="{{ route('admin.sales.orders.show', $o) }}" class="text-emerald-700 font-medium hover:underline">{{ $o->number }}</a></td>
                                <td class="py-3 px-4 text-gray-500">{{ $o->created_at?->format('Y-m-d') }}</td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ number_format((float) $o->total, 2) }}</td>
                                <td class="py-3 px-4 text-center"><x-sales.status :status="$o->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-gray-400">{{ __('لا توجد طلبات لهذا العميل.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $orders->links() }}</div>
            </div>

            {{-- تبويب الدفعات --}}
            <div x-show="tab === 'receipts'" class="overflow-x-auto" style="display:none">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('رقم السند') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الخزنة') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('المبلغ') }}</th>
                            <th class="py-3 px-4 font-medium text-center">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($receipts as $r)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-800 font-medium">{{ $r->number }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $r->voucher_date?->format('Y-m-d') }}</td>
                                <td class="py-3 px-4 text-gray-500">{{ $r->treasury?->name ?? '—' }}</td>
                                <td class="py-3 px-4 tabular-nums text-emerald-600 font-medium">{{ number_format((float) $r->amount, 2) }}</td>
                                <td class="py-3 px-4 text-center"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600">{{ $r->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-gray-400">{{ __('لا توجد دفعات لهذا العميل.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $receipts->links() }}</div>
            </div>

            {{-- تبويب كشف الحساب --}}
            <div x-show="tab === 'statement'" class="overflow-x-auto" style="display:none">
                <table class="min-w-full text-sm">
                    <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="py-3 px-4 font-medium text-start">{{ __('التاريخ') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('البيان') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('مدين (مبيعات)') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('دائن (مقبوضات)') }}</th>
                            <th class="py-3 px-4 font-medium text-start">{{ __('الرصيد') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($statement as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 text-gray-500">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('Y-m-d') : '—' }}</td>
                                <td class="py-3 px-4 text-gray-800">
                                    @if ($row['ref'])<span class="text-gray-400 font-mono text-xs me-1">{{ $row['ref'] }}</span>@endif
                                    {{ $row['desc'] }}
                                </td>
                                <td class="py-3 px-4 tabular-nums text-gray-800">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' }}</td>
                                <td class="py-3 px-4 tabular-nums text-emerald-600">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' }}</td>
                                <td class="py-3 px-4 tabular-nums font-medium text-gray-900">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-gray-400">{{ __('لا توجد حركات في كشف الحساب.') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if ($statement->isNotEmpty())
                        <tfoot class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td colspan="4" class="py-3 px-4 text-start font-bold text-gray-700">{{ __('الرصيد المتبقّي') }}</td>
                                <td class="py-3 px-4 tabular-nums font-bold {{ abs($balance) < 0.01 ? 'text-gray-900' : 'text-rose-600' }}">{{ number_format($balance, 2) }} {{ $currency }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- تبويب التفاصيل --}}
            <div x-show="tab === 'details'" class="p-6 space-y-6" style="display:none">
                @if ($customer->is_blocked && $customer->blocked_reason)
                    <p class="text-sm text-rose-700 bg-rose-50 rounded-lg px-3 py-2">{{ __('سبب الحظر') }}: {{ $customer->blocked_reason }}</p>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div class="border border-gray-100 rounded-lg p-3">
                        <div class="font-medium text-gray-700 mb-1">{{ __('الهواتف') }}</div>
                        <ul class="text-gray-600 space-y-0.5" dir="ltr">
                            @forelse ($customer->phones as $p)<li>{{ $p->phone }} @if($p->is_primary)<span class="text-xs text-emerald-600">({{ __('أساسي') }})</span>@endif</li>@empty<li class="text-gray-400">—</li>@endforelse
                        </ul>
                    </div>
                    <div class="border border-gray-100 rounded-lg p-3">
                        <div class="font-medium text-gray-700 mb-1">{{ __('العناوين') }}</div>
                        <ul class="text-gray-600 space-y-0.5">
                            @forelse ($customer->addresses as $a)<li>{{ $a->label }}: {{ $a->address_line }} @if($a->is_default)<span class="text-xs text-emerald-600">({{ __('افتراضي') }})</span>@endif</li>@empty<li class="text-gray-400">—</li>@endforelse
                        </ul>
                    </div>
                    <div class="border border-gray-100 rounded-lg p-3">
                        <div class="font-medium text-gray-700 mb-1">{{ __('المؤشّرات') }}</div>
                        <div class="text-gray-600">{{ __('نقاط الولاء') }}: {{ $customer->loyalty_points }}</div>
                        <div class="text-gray-600">{{ __('حدّ الائتمان') }}: {{ number_format((float) $customer->credit_limit, 2) }}</div>
                        <div class="text-gray-600">{{ __('إلغاءات') }}: {{ $customer->cancelled_orders_count }} · {{ __('مرتجعات') }}: {{ $customer->returns_count }}</div>
                    </div>
                </div>

                {{-- جهات الاتصال --}}
                @if ($customer->contacts->isNotEmpty())
                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 mb-2">{{ __('جهات الاتصال') }}</h4>
                        <div class="overflow-x-auto border border-gray-100 rounded-lg">
                            <table class="min-w-full text-sm">
                                <thead class="text-gray-500 bg-gray-50 border-b border-gray-100">
                                    <tr>
                                        <th class="py-2.5 px-4 font-medium text-start">{{ __('الاسم') }}</th>
                                        <th class="py-2.5 px-4 font-medium text-start">{{ __('الهاتف') }}</th>
                                        <th class="py-2.5 px-4 font-medium text-start">{{ __('البريد') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($customer->contacts as $c)
                                        <tr>
                                            <td class="py-2.5 px-4 text-gray-800">{{ $c->name }}</td>
                                            <td class="py-2.5 px-4 text-gray-500" dir="ltr">{{ $c->phone ?: '—' }}</td>
                                            <td class="py-2.5 px-4 text-gray-500">{{ $c->email ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- الملاحظات --}}
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">{{ __('الملاحظات') }}</h4>
                    @can('update', $customer)
                        <form method="POST" action="{{ route('admin.crm.customers.notes.store', $customer) }}" class="flex gap-2 mb-3">@csrf
                            <input type="text" name="body" required placeholder="{{ __('أضف ملاحظة') }}" class="rounded-lg border-gray-300 text-sm flex-1 focus:border-emerald-500 focus:ring-emerald-500" />
                            <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('إضافة') }}</button>
                        </form>
                    @endcan
                    <ul class="text-sm space-y-1">
                        @forelse ($customer->customerNotes as $n)
                            <li class="text-gray-600"><span class="text-gray-400">{{ $n->created_at?->format('Y-m-d H:i') }}</span> — {{ $n->body }} @if($n->author)<span class="text-gray-400">({{ $n->author->name }})</span>@endif</li>
                        @empty
                            <li class="text-gray-400">{{ __('لا ملاحظات.') }}</li>
                        @endforelse
                    </ul>
                </div>

                {{--
                    الدمج: هذا السجلّ يذوب في الآخر لا العكس. والقائمة مقصورة على
                    المتشابهين اسمًا أو هاتفًا — قائمةٌ بكل العملاء تجعل الخطأ
                    نقرةً واحدة.
                --}}
                @can('merge', $customer)
                    @if ($mergeCandidates->isNotEmpty())
                        <div class="border-t border-gray-100 pt-4" x-data="{ merging: false }">
                            <button type="button" @click="merging = !merging" class="text-sm text-gray-600 hover:text-gray-900">
                                {{ __('دمج هذا العميل في سجلٍّ آخر (:n مرشَّح)', ['n' => $mergeCandidates->count()]) }}
                            </button>
                            <form method="POST" action="{{ route('admin.crm.customers.merge', $customer) }}" x-show="merging" x-cloak class="mt-3 space-y-2"
                                  onsubmit="return confirm('{{ __('سيذوب «:name» في السجلّ المختار وتنتقل طلباته وسنداته ورصيده. لا يمكن التراجع.', ['name' => $customer->name]) }}')">
                                @csrf
                                <p class="text-xs text-gray-500">{{ __('اختر السجلّ الباقي — ينتقل إليه كل شيء ويُحذف هذا السجلّ.') }}</p>
                                <div class="flex flex-wrap gap-2 items-center">
                                    <select name="target" required class="rounded-lg border-gray-300 text-sm min-w-64">
                                        <option value="">{{ __('السجلّ الباقي…') }}</option>
                                        @foreach ($mergeCandidates as $candidate)
                                            <option value="{{ $candidate->uuid }}">
                                                {{ $candidate->name }}
                                                @if ($candidate->primary_phone) — {{ $candidate->primary_phone }} @endif
                                                ({{ number_format($candidate->outstandingBalance(), 2) }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="px-3 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('تأكيد الدمج') }}</button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endcan

                {{-- إجراءات الحظر --}}
                <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4" x-data="{ blocking: false }">
                    @can('block', $customer)
                        @if ($customer->is_blocked)
                            <form method="POST" action="{{ route('admin.crm.customers.unblock', $customer) }}">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('رفع الحظر') }}</button></form>
                        @else
                            <button type="button" @click="blocking = true" x-show="!blocking" class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-lg hover:bg-rose-200">{{ __('حظر العميل') }}</button>
                            <form method="POST" action="{{ route('admin.crm.customers.block', $customer) }}" x-show="blocking" x-cloak class="flex gap-2 items-center">@csrf
                                <input type="text" name="reason" required placeholder="{{ __('سبب الحظر') }}" class="rounded-lg border-gray-300 text-sm" />
                                <button class="px-3 py-2 bg-rose-600 text-white text-sm rounded-lg hover:bg-rose-700">{{ __('تأكيد الحظر') }}</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
