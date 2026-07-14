<x-app-layout :title="__('الطلبات')">
    <x-admin.header
        :title="__('طلبات البيع')"
        :description="__('إدارة طلبات المبيعات ومتابعة حالاتها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المبيعات') => null, __('الطلبات') => null]">
        @can('create', \App\Modules\Sales\Models\Order::class)
            <a href="{{ route('admin.sales.orders.direct.create') }}" class="btn-secondary btn-sm">{{ __('مبيعات مباشرة') }}</a>
            <a href="{{ route('admin.sales.orders.create') }}" class="btn-primary btn-sm">{{ __('طلب جديد') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{-- فلاتر (قوائم منسدلة): الحالة + حالة التوصيل + حالة الدفع --}}
    @php
        $statusLabels = [
            'draft' => 'مسودّة', 'confirmed' => 'مؤكّد', 'processing' => 'قيد المعالجة',
            'shipped' => 'مُشحَن', 'delivered' => 'مُسلَّم', 'cancelled' => 'مُلغى',
        ];
        $selectCls = 'rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 min-w-[10rem]';
        $hasFilter = ($activeStatus ?? null) || ($activeDeliveryStatus ?? null) || ($activePaymentStatus ?? null);
    @endphp
    <form method="GET" action="{{ route('admin.sales.orders.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('الحالة') }}</label>
            <select name="status" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل الحالات') }} ({{ $totalCount }})</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected(($activeStatus ?? null) === $s)>
                        {{ __($statusLabels[$s] ?? $s) }} ({{ (int) ($statusCounts[$s] ?? 0) }})
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('حالة أوبتيموس') }}</label>
            <select name="delivery_status" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل حالات أوبتيموس') }}</option>
                @foreach ($deliveryLabels as $key => $label)
                    <option value="{{ $key }}" @selected(($activeDeliveryStatus ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ __('حالة الدفع') }}</label>
            <select name="payment_status" onchange="this.form.submit()" class="{{ $selectCls }}">
                <option value="">{{ __('كل حالات الدفع') }}</option>
                <option value="paid" @selected(($activePaymentStatus ?? null) === 'paid')>{{ __('مدفوع') }}</option>
                <option value="unpaid" @selected(($activePaymentStatus ?? null) === 'unpaid')>{{ __('غير مدفوع') }}</option>
                <option value="partial" @selected(($activePaymentStatus ?? null) === 'partial')>{{ __('مدفوع جزئيًا') }}</option>
            </select>
        </div>

        <noscript><button type="submit" class="btn-secondary btn-sm">{{ __('فلترة') }}</button></noscript>
        @if ($hasFilter)
            <a href="{{ route('admin.sales.orders.index') }}" class="btn-secondary btn-sm">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('رقم التتبّع') }}</th>
                <th>{{ __('التاريخ والوقت') }}</th>
                <th>{{ __('اسم المستلم') }}</th>
                <th>{{ __('المستخدم') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th>{{ __('حالة أوبتيموس') }}</th>
                <th>{{ __('حالة الدفع') }}</th>
                <th class="text-start">{{ __('الإجمالي') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $o)
                <tr>
                    <td class="font-mono text-xs">
                        @if ($o->tracking_number)
                            <span class="font-semibold text-gray-900">{{ $o->tracking_number }}</span>
                        @else
                            <span class="text-amber-500">{{ __('بانتظار التتبّع') }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-gray-600">
                        <div>{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('Y-m-d') }}</div>
                        <div class="text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('h:i A') }}</div>
                    </td>
                    <td class="font-medium text-gray-800">
                        {{ $o->latestShipment?->recipient_name ?: ($o->customer_name ?: '—') }}
                        @if ($o->channel === 'pos')
                            <span class="inline-flex items-center rounded-md bg-violet-50 text-violet-700 text-[11px] px-1.5 py-0.5 ms-1">{{ __('مبيعات مباشرة') }}</span>
                        @endif
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
                    <td><x-sales.status :status="$o->status" /></td>
                    <td class="whitespace-nowrap text-xs">
                        @php $ps = $o->latestShipment?->provider_status; @endphp
                        @if ($ps)
                            <div class="flex flex-col items-start gap-1">
                                <span class="inline-flex items-center rounded-md bg-sky-50 text-sky-700 px-2 py-0.5">{{ \App\Modules\Shipping\Support\OpostStatus::label($ps) }}</span>
                                @if ($ps === 'delivered')
                                    @if ($o->return_received_at)
                                        <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            {{ __('استُلم في المستودع') }}
                                        </span>
                                    @else
                                        @can('update', $o)
                                            <x-admin.confirm
                                                :action="route('admin.sales.orders.receive_return', $o)"
                                                method="POST"
                                                tone="green"
                                                :trigger="__('تأكيد استلام المرتجع')"
                                                :title="__('تأكيد استلام المرتجع')"
                                                :confirm="__('تأكيد وإرجاع للمخزون')"
                                                :message="__('سيتم تأكيد رجوع الطلب للمستودع وإعادة كمياته إلى المخزون. لا يمكن التراجع.')" />
                                        @endcan
                                    @endif
                                @endif
                            </div>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td>
                        @if ($o->payment_status === 'paid')
                            <x-admin.badge tone="green" :label="__('مدفوع')" />
                        @else
                            <x-admin.badge tone="amber" :label="__('غير مدفوع')" />
                        @endif
                    </td>
                    <td class="text-start font-medium tabular-nums whitespace-nowrap">{{ number_format($o->total, 2) }} {{ \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', '₪') }}</td>
                    <td class="text-end whitespace-nowrap">
                        <div class="inline-flex items-center gap-3">
                            @if ($o->status === 'draft')
                                @can('confirm', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.confirm', $o) }}">
                                        @csrf
                                        <button type="submit" class="btn-primary btn-sm">{{ __('تأكيد الطلب') }}</button>
                                    </form>
                                @endcan
                            @endif
                            <a href="{{ route('admin.sales.orders.show', $o) }}" class="text-emerald-600 hover:underline text-sm">{{ __('عرض') }}</a>
                            {{-- إلغاء طلب ما زال «بانتظار الاستلام» لدى أوبتيموس — يُلغي الشحنة من الشركة أيضًا --}}
                            @if (in_array($o->latestShipment?->provider_status, ['submitted', 'submit', 'pending'], true) && $o->status !== 'cancelled')
                                @can('cancel', $o)
                                    <form method="POST" action="{{ route('admin.sales.orders.cancel', $o) }}"
                                          onsubmit="return confirm('{{ __('إلغاء الطلب وإلغاء الشحنة من شركة التوصيل؟') }}')">
                                        @csrf
                                        <input type="hidden" name="reason" value="{{ __('إلغاء قبل الاستلام') }}" />
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
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا توجد طلبات')"
                        :description="($activeStatus ?? null) ? __('لا توجد طلبات بهذه الحالة.') : __('ابدأ بإنشاء أول طلب بيع.')"
                        :icon="'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218'" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4">{{ $orders->links() }}</div>
</x-app-layout>
