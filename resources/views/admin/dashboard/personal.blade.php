{{--
    الشاشة الرئيسية لموظف المبيعات والمسوّق.

    ما يخصّه هو فقط: طلباته، ومبيعاته بلا رسوم توصيل (وهي أساس عمولته)، وما استحقّه
    وما زال قيد التحصيل، ثم الطلبات المتوقّفة بانتظار تصرّف منه — فالعمل يبدأ من
    مشكلة لا من رقم. لا أرصدة خزائن ولا أداء زملاء.
--}}
@php
    $isAffiliate = $earnerType === 'affiliate';
    $earningsLabel = $isAffiliate ? __('أرباحي') : __('عمولتي');
@endphp

<x-app-layout :title="__('dashboard.title')">
    <x-admin.header
        :title="__('أهلًا :name', ['name' => auth()->user()->name])"
        :description="__('ملخّص يومك — :date', ['date' => now()->translatedFormat('l j F Y')])">
        @can('create', \App\Modules\Sales\Models\Order::class)
            <a href="{{ route('admin.sales.orders.create') }}" class="btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('طلب بيع جديد') }}
            </a>
        @endcan
    </x-admin.header>

    {{-- المؤشّرات الشخصية --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <x-admin.stat-card :label="__('طلباتي اليوم')" :value="$todayOrders" tone="blue"
            icon="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />

        <x-admin.stat-card :label="__('مبيعاتي اليوم')" :value="$todaySales" money tone="green"
            icon="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />

        <x-admin.stat-card :label="$earningsLabel.' '.__('المستحقّة')" :value="$balance['outstanding']" money tone="green"
            icon="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.504-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872" />

        <x-admin.stat-card :label="__('قيد التحصيل')" :value="$statement['pending']" money tone="amber"
            icon="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    <div class="mt-4">
    <x-admin.alert tone="blue">
        {{ $isAffiliate
            ? __('ربحك هو الفرق بين سعر البيع وسعر الجملة، ويصبح مستحقًّا عند وصول الطلب إلى «الحسابات» لدى شركة التوصيل.')
            : __('عمولتك تُحتسب على قيمة المبيعات بدون رسوم التوصيل، وتصبح مستحقّة عند وصول الطلب إلى «الحسابات» لدى شركة التوصيل.') }}
    </x-admin.alert>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6">
        {{-- ما يحتاج متابعته: الطلبات المتوقّفة أولًا --}}
        <div class="lg:col-span-2 admin-card">
            <div class="flex items-center justify-between px-4 md:px-5 py-3 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800">{{ __('يحتاج متابعتك') }}</h3>
                @if ($needsAttention->isNotEmpty())
                    <span class="badge badge-amber">{{ $needsAttention->count() }}</span>
                @endif
            </div>

            @forelse ($needsAttention as $order)
                <a href="{{ route('admin.sales.orders.show', $order) }}"
                   class="flex items-center gap-3 px-4 md:px-5 py-3 border-b border-gray-50 last:border-0 hover:bg-gray-50 transition">
                    <span class="grid place-items-center w-9 h-9 rounded-lg bg-amber-50 text-amber-600 shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-gray-800 text-sm font-mono">{{ $order->number }}</span>
                        <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($order->created_at)->diffForHumans() }}</span>
                    </span>
                    <x-sales.status :status="$order->status" />
                    <span class="text-sm font-semibold text-gray-700 tabular-nums shrink-0">{{ number_format($order->total, 2) }}</span>
                </a>
            @empty
                <x-admin.empty-state :title="__('لا شيء متوقّف')"
                    :description="__('كل طلباتك تسير في مسارها الطبيعي.')"
                    :icon="'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'" />
            @endforelse
        </div>

        {{-- ملخّص المستحقّات --}}
        <div class="admin-card admin-card-pad">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('ملخّص :label', ['label' => $earningsLabel]) }}</h3>

            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('مسجّلة') }}</dt>
                    <dd class="font-medium text-gray-800 tabular-nums">{{ number_format($balance['earned'], 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('قيد التحصيل') }}</dt>
                    <dd class="font-medium text-amber-600 tabular-nums">{{ number_format($statement['pending'], 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('مدفوعة سابقًا') }}</dt>
                    <dd class="font-medium text-gray-800 tabular-nums">{{ number_format($balance['paid'], 2) }}</dd>
                </div>
                @if ($balance['pending_payout'] > 0)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('سند صرف لم يُرحَّل') }}</dt>
                        <dd class="font-medium text-gray-800 tabular-nums">{{ number_format($balance['pending_payout'], 2) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between pt-3 border-t border-gray-200">
                    <dt class="font-semibold text-gray-800">{{ __('المستحقّ لي') }}</dt>
                    <dd class="text-xl font-bold text-emerald-600 tabular-nums">{{ number_format($balance['outstanding'], 2) }}</dd>
                </div>
            </dl>

            <dl class="mt-5 pt-4 border-t border-gray-100 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-gray-500 text-xs">{{ __('طلبات الشهر') }}</dt>
                    <dd class="mt-0.5 text-lg font-bold text-gray-900 tabular-nums">{{ $monthOrders }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 text-xs">{{ __('مبيعات الشهر') }}</dt>
                    <dd class="mt-0.5 text-lg font-bold text-gray-900 tabular-nums">{{ number_format($monthSales, 2) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- آخر الطلبات --}}
    <div class="mt-6">
        <x-admin.table :title="__('آخر طلباتي')">
            <thead>
                <tr>
                    <th>{{ __('رقم الطلب') }}</th>
                    <th>{{ __('الحالة') }}</th>
                    <th class="text-start">{{ __('الإجمالي') }}</th>
                    <th class="text-start">{{ __('التاريخ') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($latestOrders as $order)
                    <tr>
                        <td class="font-mono text-xs">
                            <a href="{{ route('admin.sales.orders.show', $order) }}" class="text-emerald-700 hover:underline">{{ $order->number }}</a>
                        </td>
                        <td><x-sales.status :status="$order->status" /></td>
                        <td class="text-start font-medium tabular-nums">{{ number_format($order->total, 2) }}</td>
                        <td class="text-start text-gray-400 text-xs">{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="!p-0">
                        <x-admin.empty-state :title="__('لا توجد طلبات بعد')"
                            :description="__('ابدأ بإنشاء أول طلب من زرّ «طلب بيع جديد».')" />
                    </td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
