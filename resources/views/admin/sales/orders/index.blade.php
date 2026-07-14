<x-app-layout :title="__('الطلبات')">
    <x-admin.header
        :title="__('طلبات البيع')"
        :description="__('إدارة طلبات المبيعات ومتابعة حالاتها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المبيعات') => null, __('الطلبات') => null]">
        @can('create', \App\Modules\Sales\Models\Order::class)
            <a href="{{ route('admin.sales.orders.create') }}" class="btn-primary btn-sm">{{ __('طلب جديد') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    {{-- فلتر الحالات --}}
    @php
        $labels = [
            'draft' => 'مسودّة', 'confirmed' => 'مؤكّد', 'processing' => 'قيد المعالجة',
            'shipped' => 'مُشحَن', 'delivered' => 'مُسلَّم', 'cancelled' => 'مُلغى',
        ];
        $tab = fn ($key, $label, $count, $active) => compact('key', 'label', 'count', 'active');
        $tabs = [$tab(null, __('الكل'), $totalCount, ($activeStatus ?? null) === null)];
        foreach ($statuses as $s) {
            $tabs[] = $tab($s, __($labels[$s] ?? $s), (int) ($statusCounts[$s] ?? 0), ($activeStatus ?? null) === $s);
        }
    @endphp
    <div class="flex items-center gap-2 flex-wrap mb-4">
        @foreach ($tabs as $t)
            <a href="{{ $t['key'] ? route('admin.sales.orders.index', ['status' => $t['key']]) : route('admin.sales.orders.index') }}"
               @class([
                   'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition border',
                   'bg-emerald-600 text-white border-emerald-600' => $t['active'],
                   'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' => !$t['active'],
               ])>
                <span>{{ $t['label'] }}</span>
                <span @class([
                    'text-xs rounded-full px-1.5 min-w-[1.25rem] text-center',
                    'bg-white/20 text-white' => $t['active'],
                    'bg-gray-100 text-gray-500' => !$t['active'],
                ])>{{ $t['count'] }}</span>
            </a>
        @endforeach
    </div>

    <x-admin.table>
        <thead>
            <tr>
                <th>{{ __('رقم الطلب') }}</th>
                <th>{{ __('التاريخ والوقت') }}</th>
                <th>{{ __('اسم المستلم') }}</th>
                <th>{{ __('المستخدم') }}</th>
                <th>{{ __('الحالة') }}</th>
                <th>{{ __('حالة الدفع') }}</th>
                <th class="text-start">{{ __('الإجمالي') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $o)
                <tr>
                    <td class="font-mono text-xs text-gray-800">{{ $o->number }}</td>
                    <td class="whitespace-nowrap text-gray-600">
                        <div>{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('Y-m-d') }}</div>
                        <div class="text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('h:i A') }}</div>
                    </td>
                    <td class="font-medium text-gray-800">{{ $o->latestShipment?->recipient_name ?: ($o->customer_name ?: '—') }}</td>
                    <td>
                        @php $staff = $o->assignee ?? ($o->channel === 'manual' ? $o->creator : null); @endphp
                        @if ($staff)
                            <span class="text-gray-700">{{ $staff->name }}</span>
                        @elseif ($o->channel === 'manual')
                            <span class="text-gray-700">{{ __('موظف المبيعات') }}</span>
                        @elseif ($o->customer?->user_id)
                            <span class="text-gray-700">{{ $o->customer->name }}</span>
                        @else
                            <x-admin.badge tone="blue" :label="__('زبون')" :icon="false" />
                        @endif
                    </td>
                    <td><x-sales.status :status="$o->status" /></td>
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
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="!p-0">
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
