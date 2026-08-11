<x-app-layout :title="__('الطلبات')">
    <x-admin.header
        :title="__('طلبات البيع')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المبيعات') => null, __('الطلبات') => null]">
        @can('createDirect', \App\Modules\Sales\Models\Order::class)
            <a href="{{ route('admin.sales.orders.direct.create') }}" class="btn-secondary btn-sm">{{ __('مبيعات مباشرة') }}</a>
        @endcan
        @can('create', \App\Modules\Sales\Models\Order::class)
            <a href="{{ route('admin.sales.orders.create') }}" class="btn-primary btn-sm">{{ __('انشاء اوردر') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{-- مؤشّر حالة إرسال الطلبات لشركة التوصيل — لأصحاب العرض الكامل فقط (مؤشّر تشغيلي) --}}
    @can('sales.orders.view')
        <x-admin.delivery-status />
    @endcan

    {{-- فلاتر: نوع البيع + حالة أوبتيموس + حالة الدفع. الحالة الداخلية غير معروضة
         (المتابعة التشغيلية تعتمد حالة شركة التوصيل وحدها — قرار إداري). --}}
    @php
        $selectCls = 'rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 min-w-[10rem]';
        $hasFilter = ($activeStatus ?? null) || ($activeDeliveryStatus ?? null) || ($activePaymentStatus ?? null) || ($activeSearch ?? null) || ($activeSaleType ?? null);
    @endphp

    {{-- البحث + الفلاتر في صفٍّ واحد (البحث بجانب الفلاتر) --}}
    <form method="GET" action="{{ route('admin.sales.orders.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div class="flex items-stretch gap-2">
            <div class="relative w-full sm:w-72">
                <span class="absolute inset-y-0 start-0 ps-3 flex items-center text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                </span>
                <input type="text" name="search" value="{{ $activeSearch }}"
                       placeholder="{{ __('بحث برقم التتبّع أو اسم المستلم...') }}"
                       class="w-full h-full ps-9 pe-3 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>
            <button type="submit" class="btn-secondary btn-sm shrink-0">{{ __('بحث') }}</button>
        </div>

        <div>
            <select name="sale_type" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل المبيعات') }}</option>
                <option value="normal" @selected(($activeSaleType ?? null) === 'normal')>{{ __('مبيعات عادية') }}</option>
                <option value="direct" @selected(($activeSaleType ?? null) === 'direct')>{{ __('مبيعات مباشرة') }}</option>
            </select>
        </div>
        <div>
            <select name="delivery_status" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل حالات أوبتيموس') }}</option>
                @foreach ($deliveryLabels as $key => $label)
                    <option value="{{ $key }}" @selected(($activeDeliveryStatus ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <select name="payment_status" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل حالات الدفع') }}</option>
                <option value="paid" @selected(($activePaymentStatus ?? null) === 'paid')>{{ __('مدفوع') }}</option>
                <option value="unpaid" @selected(($activePaymentStatus ?? null) === 'unpaid')>{{ __('غير مدفوع') }}</option>
                <option value="partial" @selected(($activePaymentStatus ?? null) === 'partial')>{{ __('مدفوع جزئيًا') }}</option>
            </select>
        </div>

        @if ($hasFilter)
            <a href="{{ route('admin.sales.orders.index') }}" class="btn-secondary btn-sm">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>

    {{-- نموذج الحذف الجماعي (فارغ؛ صناديق التحديد تنضمّ إليه عبر السمة form=) --}}
    @can('sales.orders.delete')
        <form id="orders-bulk-delete" method="POST" action="{{ route('admin.sales.orders.bulk_destroy') }}"
              onsubmit="return confirm('{{ __('حذف الطلبات المحدَّدة نهائيًا؟') }}')">
            @csrf @method('DELETE')
        </form>
    @endcan

    <div x-data="{
            count: 0,
            recount() { this.count = document.querySelectorAll('input[data-row-check]:checked').length; },
            toggleAll(e) { document.querySelectorAll('input[data-row-check]:not(:disabled)').forEach(c => c.checked = e.target.checked); this.recount(); }
         }">
        @can('sales.orders.delete')
            <div class="flex items-center justify-end mb-3" x-show="count > 0" x-cloak>
                <button type="submit" form="orders-bulk-delete" class="btn-danger btn-sm">
                    {{ __('حذف المحدَّد') }} (<span x-text="count"></span>)
                </button>
            </div>
        @endcan

    <x-admin.table>
        <thead>
            <tr>
                @can('sales.orders.delete')
                    <th class="w-8">
                        <input type="checkbox" x-on:change="toggleAll($event)"
                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                    </th>
                @endcan
                <th>{{ __('رقم التتبّع') }}</th>
                <th>{{ __('التاريخ والوقت') }}</th>
                <th>{{ __('اسم المستلم') }}</th>
                <th>{{ __('المستخدم') }}</th>
                <th>{{ __('حالة أوبتيموس') }}</th>
                <th>{{ __('حالة الدفع') }}</th>
                <th class="text-start">{{ __('الإجمالي') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $o)
                <tr>
                    @can('sales.orders.delete')
                        @php
                            $deletable = \App\Http\Controllers\Admin\Sales\OrderController::isDeletable($o);
                        @endphp
                        <td class="w-8">
                            <input type="checkbox" name="ids[]" value="{{ $o->id }}" form="orders-bulk-delete"
                                   data-row-check x-on:change="recount()"
                                   @disabled(! $deletable)
                                   title="{{ $deletable ? '' : __('لا يمكن حذف هذا الطلب (يجب أن يكون ملغى وشحنته ملغاة)') }}"
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 disabled:opacity-30 disabled:cursor-not-allowed" />
                        </td>
                    @endcan
                    <td class="font-mono text-xs">
                        {{-- رقم التتبّع/الطلب رابطٌ لصفحة التفاصيل (بديلًا عن زر «عرض») --}}
                        <a href="{{ route('admin.sales.orders.show', $o) }}" class="group inline-flex flex-col hover:underline">
                            @if ($o->channel === 'pos')
                                <span class="inline-flex items-center rounded-md bg-violet-50 text-violet-700 text-[11px] px-1.5 py-0.5">{{ __('مبيعات مباشرة') }}</span>
                            @elseif ($o->tracking_number)
                                <span class="font-semibold text-emerald-700 group-hover:text-emerald-800">{{ $o->tracking_number }}</span>
                            @elseif ($o->delivery_dispatch_error)
                                {{-- فشل الإرسال: يُعاد تلقائيًا كل دقيقة — السبب ظاهر بدل «بانتظار» غامضة --}}
                                <span class="text-rose-600" title="{{ $o->delivery_dispatch_error }}">
                                    {{ __('تعذّر الإرسال — إعادة محاولة') }}
                                    @if ($o->delivery_dispatch_attempts > 1)({{ $o->delivery_dispatch_attempts }})@endif
                                </span>
                                <span class="text-[10px] text-rose-400 truncate max-w-[220px]" title="{{ $o->delivery_dispatch_error }}">{{ $o->delivery_dispatch_error }}</span>
                            @else
                                <span class="text-amber-500">{{ __('بانتظار التتبّع') }}</span>
                            @endif
                            <span class="text-[10px] text-gray-400 font-sans">{{ $o->number }}</span>
                        </a>
                    </td>
                    <td class="whitespace-nowrap text-gray-600">
                        <div>{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('Y-m-d') }}</div>
                        <div class="text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('h:i A') }}</div>
                    </td>
                    <td class="font-medium text-gray-800">
                        {{ $o->latestShipment?->recipient_name ?: ($o->customer_name ?: '—') }}
                    </td>
                    <td>
                        @php $staff = $o->assignee ?? ($o->channel === 'manual' ? $o->creator : null); @endphp
                        @if ($staff || $o->channel === 'manual')
                            {{-- موظف مبيعات: نُظهر الوصف واسمه أسفله --}}
                            <span class="block text-xs text-gray-400">{{ __('موظف المبيعات') }}</span>
                            <span class="text-gray-700">{{ $staff?->name ?? '—' }}</span>
                        @elseif ($o->customer?->user_id)
                            <span class="text-gray-700">{{ $o->customer->name }}</span>
                        @else
                            <x-admin.badge tone="blue" :label="__('زبون')" :icon="false" />
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-xs">
                        @php $ps = $o->latestShipment?->provider_status; @endphp
                        @if ($ps)
                            <div class="flex flex-col items-start gap-1">
                                <span class="inline-flex items-center rounded-md bg-sky-50 text-sky-700 px-2 py-0.5">{{ \App\Modules\Shipping\Support\OpostStatus::label($ps) }}</span>
                                @if ($ps === 'delivered' && $o->return_received_at)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                        {{ __('استُلم في المستودع') }}
                                    </span>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        <x-payment.status :status="$o->payment_status ?: 'unpaid'" />
                        @if ($o->payment_status === 'partially_paid')
                            @php $rem = max(0, round((float) $o->total - (float) $o->amount_paid, 2)); @endphp
                            <span class="block text-[11px] text-gray-400 mt-0.5">{{ __('المتبقّي') }}: {{ number_format($rem, 2) }}</span>
                        @endif
                    </td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($o->total, 2) }} {{ \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪') }}</td>
                    <td class="text-end">
                        @php
                            $isEditable = \App\Http\Controllers\Admin\Sales\OrderController::isEditable($o);
                            $isCancellable = ! in_array($o->status, ['cancelled', 'delivered', 'returned'], true);
                        @endphp
                        <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1.5">
                            @if ($o->status === 'draft')
                                @can('confirm', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.confirm', $o) }}">
                                        @csrf
                                        <button type="submit" class="btn-primary btn-sm">{{ __('تأكيد الطلب') }}</button>
                                    </form>
                                @endcan
                            @endif
                            {{-- مرتجع مع السائق: زر أحمر لتأكيد استلام المرتجع (نفس مكان زر تأكيد الطلب) --}}
                            @if ($o->latestShipment?->provider_status === 'delivered' && ! $o->return_received_at)
                                @can('update', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.receive_return', $o) }}"
                                          onsubmit="return confirm('{{ __('تأكيد استلام المرتجع وإعادة الكميات إلى المخزون؟') }}')">
                                        @csrf
                                        <button type="submit" class="btn-danger btn-sm">{{ __('تأكيد استلام المرتجع') }}</button>
                                    </form>
                                @endcan
                            @endif
                            {{-- فاتورة رسمية (عرض/طباعة) تُظهر المدفوع والمتبقّي --}}
                            <a href="{{ route('admin.sales.orders.invoice', $o) }}" target="_blank" class="text-slate-600 hover:underline text-sm">{{ __('فاتورة') }}</a>
                            {{-- تعديل بيانات التواصل/التوصيل (تصحيح بيانات خاطئة) قبل الإرسال لشركة التوصيل --}}
                            @if ($isEditable)
                                @can('update', $o)
                                    <a href="{{ route('admin.sales.orders.edit', $o) }}" class="text-emerald-600 hover:underline text-sm">{{ __('تعديل') }}</a>
                                @endcan
                            @endif
                            {{-- مبيعات مباشرة غير مسدَّدة: زر «دفع» يفتح نافذة تحصيل (مبلغ + خزينة) --}}
                            @if ($o->channel === 'pos' && $o->payment_status !== 'paid' && $o->status !== 'cancelled')
                                @can('update', $o)
                                    @php($outstanding = round((float) $o->total - (float) $o->amount_paid, 2))
                                    <button type="button" x-data
                                            data-action="{{ route('admin.sales.orders.settle', $o) }}"
                                            data-number="{{ $o->number }}"
                                            data-outstanding="{{ $outstanding }}"
                                            @click="$dispatch('open-pay', { number: $el.dataset.number, outstanding: Number($el.dataset.outstanding), action: $el.dataset.action })"
                                            class="btn-primary btn-sm">{{ __('دفع') }}</button>
                                @endcan
                            @endif
                            {{-- تجاوز إداري: تحويل فاتورة طلب توصيل إلى «مدفوعة» حين لا تصل حالة المزوّد (مدير النظام فقط) --}}
                            @if ($o->channel !== 'pos' && $o->payment_status !== 'paid' && $o->status !== 'cancelled' && $o->latestShipment)
                                @can('markPaid', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.mark_paid', $o) }}"
                                          onsubmit="return confirm('{{ __('تحويل الفاتورة إلى «مدفوعة»؟ يُثبت أن المندوب قبض المبلغ من العميل.') }}')">
                                        @csrf
                                        <button type="submit" class="text-emerald-700 hover:underline text-sm font-medium">{{ __('تحويل إلى مدفوعة') }}</button>
                                    </form>
                                @endcan
                            @endif
                            {{-- إلغاء الطلب: للطلبات غير المُنتهية (يعكس الأثر المحاسبي/المخزوني ويُلغي الشحنة إن أُرسلت). المبيعات المباشرة تُلغى بالحذف النهائي. --}}
                            @if ($isCancellable && $o->channel !== 'pos')
                                @can('cancel', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.cancel', $o) }}"
                                          onsubmit="return confirm('{{ __('إلغاء الطلب؟ سيُعكَس أثره المحاسبي والمخزوني وتُلغى الشحنة من شركة التوصيل إن وُجدت.') }}')">
                                        @csrf
                                        <input type="hidden" name="reason" value="{{ __('إلغاء الطلب') }}" />
                                        <button type="submit" class="text-rose-600 hover:underline text-sm">{{ __('إلغاء الطلب') }}</button>
                                    </form>
                                @endcan
                            @endif
                            @if (\App\Http\Controllers\Admin\Sales\OrderController::isDeletable($o))
                                @can('delete', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.destroy', $o) }}"
                                          onsubmit="return confirm('{{ __('حذف الطلب نهائيًا؟') }}')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-rose-600 hover:underline text-sm">{{ __('حذف') }}</button>
                                    </form>
                                @endcan
                            @endif
                            {{-- حذف إداري نهائي لأي طلب مع عكس الأثر المحاسبي — للأدمن فقط --}}
                            @can('forceDelete', $o)
                                @unless ($o->trashed())
                                    <form method="POST" action="{{ route('admin.sales.orders.force_destroy', $o) }}"
                                          onsubmit="return confirm('{{ __('حذف نهائي للطلب مع عكس كل أثره المحاسبي والمخزوني (القيود + المخزون + الدفعات + العمولات)؟ لا يمكن التراجع.') }}')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-rose-700 hover:underline text-sm font-medium">{{ __('حذف نهائي') }}</button>
                                    </form>
                                @endunless
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ auth()->user()?->can('sales.orders.delete') ? 10 : 9 }}" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا توجد طلبات')"
                        :description="($activeStatus ?? null) ? __('لا توجد طلبات بهذه الحالة.') : __('ابدأ بإنشاء أول طلب بيع.')"
                        :icon="'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218'" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>

    {{-- نافذة تحصيل دفعة (كامل/جزئي) إلى خزينة نقدية أو حساب بنكي --}}
    <div x-data="{ show: false, number: '', outstanding: 0, action: '' }"
         x-on:open-pay.window="show = true; number = $event.detail.number; outstanding = $event.detail.outstanding; action = $event.detail.action; $nextTick(() => $refs.amount && ($refs.amount.value = $event.detail.outstanding))"
         x-show="show" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
         style="display:none" @click.self="show = false" @keydown.escape.window="show = false">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">{{ __('تحصيل دفعة') }} <span class="text-sm text-gray-400 font-mono" x-text="number"></span></h3>
                <button type="button" @click="show = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form :action="action" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm text-gray-600 mb-1">
                        {{ __('المبلغ') }}
                        <span class="text-gray-400 text-xs">({{ __('المتبقّي') }}: <span x-text="Number(outstanding).toFixed(2)"></span>)</span>
                    </label>
                    <input type="number" name="amount" x-ref="amount" step="0.01" min="0.01" :max="outstanding" required
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <button type="button" @click="$refs.amount.value = outstanding" class="mt-1 text-xs text-emerald-600 hover:underline">{{ __('المبلغ كامل') }}</button>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('إيداع في') }}</label>
                    <select name="treasury_id" required class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">{{ __('— اختر الخزينة/الحساب —') }}</option>
                        @php($cashBoxes = $treasuries->where('type', 'cash'))
                        @php($bankAccounts = $treasuries->where('type', 'bank'))
                        @if ($cashBoxes->isNotEmpty())
                            <optgroup label="{{ __('الخزائن النقدية') }}">
                                @foreach ($cashBoxes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </optgroup>
                        @endif
                        @if ($bankAccounts->isNotEmpty())
                            <optgroup label="{{ __('الحسابات البنكية') }}">
                                @foreach ($bankAccounts as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="show = false" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg">{{ __('إلغاء') }}</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">{{ __('تحصيل') }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
