<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('dashboard.title') }}</h2>
            @can('reports.view')<a href="{{ route('admin.reports.index') }}" class="text-sm text-indigo-600 hover:underline">{{ __('dashboard.view_reports') }}</a>@endcan
        </div>
    </x-slot>

    @php $cur = \App\Modules\Foundation\Services\Settings::get('store.currency_symbol', config('app.currency', '')); @endphp

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- بطاقات المؤشّرات الرئيسة --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ([
                ['label' => __('dashboard.today_sales'), 'value' => number_format($todaySales, 2), 'bar' => 'bg-emerald-500'],
                ['label' => __('dashboard.month_sales'), 'value' => number_format($month['sales']['total'], 2), 'bar' => 'bg-indigo-500'],
                ['label' => __('dashboard.orders'), 'value' => $month['sales']['orders'], 'bar' => 'bg-sky-500'],
                ['label' => __('dashboard.revenue'), 'value' => number_format($month['sales']['collected'], 2), 'bar' => 'bg-teal-500'],
                ['label' => __('dashboard.profit'), 'value' => number_format($month['sales']['gross_profit'], 2), 'bar' => 'bg-fuchsia-500'],
                ['label' => __('dashboard.aov'), 'value' => number_format($month['sales']['avg_order_value'], 2), 'bar' => 'bg-amber-500'],
                ['label' => __('dashboard.campaigns_sent'), 'value' => $month['campaigns']['sent'], 'bar' => 'bg-rose-500'],
                ['label' => __('dashboard.reco_clicks'), 'value' => $month['recommendations']['clicks'], 'bar' => 'bg-violet-500'],
            ] as $card)
                <div class="bg-white shadow-sm rounded-lg border border-gray-100 p-4">
                    <div class="h-1 w-8 rounded {{ $card['bar'] }} mb-2"></div>
                    <p class="text-2xl font-bold text-gray-900">{{ $card['value'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $card['label'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- القسم المالي (Phase 7.1) — من السندات/القيود المُرحّلة --}}
        @isset($finance)
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ([
                    ['label' => __('إجمالي الخزائن'), 'value' => number_format($finance['cashbox_total'], 2), 'bar' => 'bg-emerald-500'],
                    ['label' => __('إجمالي البنوك'), 'value' => number_format($finance['bank_total'], 2), 'bar' => 'bg-sky-500'],
                    ['label' => __('قبض اليوم'), 'value' => number_format($finance['today_receipts'], 2), 'bar' => 'bg-teal-500'],
                    ['label' => __('صرف اليوم'), 'value' => number_format($finance['today_payments'], 2), 'bar' => 'bg-rose-500'],
                    ['label' => __('مصروفات الشهر'), 'value' => number_format($finance['monthly_expenses'], 2), 'bar' => 'bg-amber-500'],
                    ['label' => __('إيرادات أخرى (شهر)'), 'value' => number_format($finance['monthly_income'], 2), 'bar' => 'bg-indigo-500'],
                    ['label' => __('سندات غير مُرحّلة'), 'value' => $finance['unposted'], 'bar' => 'bg-fuchsia-500'],
                    ['label' => __('سندات معكوسة'), 'value' => $finance['reversed'], 'bar' => 'bg-violet-500'],
                ] as $card)
                    <div class="bg-white shadow-sm rounded-lg border border-gray-100 p-4">
                        <div class="h-1 w-8 rounded {{ $card['bar'] }} mb-2"></div>
                        <p class="text-xl font-bold text-gray-900">{{ $card['value'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $card['label'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white shadow-sm rounded-lg p-5">
                    <h3 class="font-semibold text-gray-700 mb-4">{{ __('حركة النقد (الشهر)') }}</h3>
                    @php $fmax = max(1, $finance['cash_daily']->max(fn ($r) => max($r->inflow, $r->outflow)) ?? 1); @endphp
                    @if ($finance['cash_daily']->isNotEmpty())
                        <div class="flex items-end gap-1 h-32 overflow-x-auto">
                            @foreach ($finance['cash_daily'] as $r)
                                <div class="flex flex-col items-center justify-end flex-1 min-w-[8px]" title="{{ $r->d }}">
                                    <div class="w-full bg-emerald-500 rounded-t" style="height: {{ (int) round(($r->inflow / $fmax) * 100) }}%"></div>
                                    <div class="w-full bg-rose-400" style="height: {{ (int) round(($r->outflow / $fmax) * 60) }}%"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-4 mt-2 text-xs text-gray-500"><span>■ {{ __('وارد') }}</span><span class="text-rose-400">■ {{ __('صادر') }}</span></div>
                    @else
                        <p class="text-sm text-gray-400 py-8 text-center">{{ __('dashboard.no_data') }}</p>
                    @endif
                </div>
                <div class="bg-white shadow-sm rounded-lg p-5">
                    <h3 class="font-semibold text-gray-700 mb-3">{{ __('أحدث الحركات المالية') }}</h3>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($finance['recent'] as $v)
                                <tr class="border-b last:border-0">
                                    <td class="py-1.5 text-xs">{{ __('accounting.kind.'.$v->kind) }}</td>
                                    <td class="py-1.5 text-gray-500 text-xs">{{ $v->treasury?->name }}</td>
                                    <td class="py-1.5 text-end font-medium">{{ number_format($v->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-3 text-gray-400">{{ __('dashboard.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endisset

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- مخطّط مبيعات الشهر (أعمدة CSS، RTL، قابل للطباعة) --}}
            <div class="lg:col-span-2 bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-4">{{ __('dashboard.sales_chart') }}</h3>
                @php $max = max(1, $salesDaily->max('t') ?? 1); @endphp
                @if ($salesDaily->isNotEmpty())
                    <div class="flex items-end gap-1 h-40 overflow-x-auto">
                        @foreach ($salesDaily as $row)
                            <div class="flex flex-col items-center justify-end flex-1 min-w-[10px]" title="{{ $row->d }}: {{ number_format($row->t, 2) }}">
                                <div class="w-full bg-indigo-500 rounded-t" style="height: {{ max(2, (int) round(($row->t / $max) * 100)) }}%"></div>
                                <span class="text-[9px] text-gray-400 mt-1">{{ \Illuminate\Support\Str::afterLast($row->d, '-') }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400 py-8 text-center">{{ __('dashboard.no_data') }}</p>
                @endif
            </div>

            {{-- ملخّص المستودع --}}
            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-4">{{ __('dashboard.warehouse') }}</h3>
                @if ($warehouse)
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-gray-500">{{ __('dashboard.skus') }}</dt><dd class="font-bold">{{ $warehouse['skus'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.available') }}</dt><dd class="font-bold">{{ rtrim(rtrim(number_format($warehouse['available'], 2), '0'), '.') }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.reserved') }}</dt><dd class="font-bold">{{ rtrim(rtrim(number_format($warehouse['reserved'], 2), '0'), '.') }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.low_stock') }}</dt><dd class="font-bold text-rose-600">{{ $warehouse['low_stock'] }}</dd></div>
                        <div class="col-span-2"><dt class="text-gray-500">{{ __('dashboard.stock_value') }}</dt><dd class="font-bold">{{ number_format($warehouse['stock_value'], 2) }}</dd></div>
                    </dl>
                @else
                    <p class="text-sm text-gray-400">{{ __('dashboard.no_data') }}</p>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- أحدث الطلبات --}}
            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-3">{{ __('dashboard.latest_orders') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse ($latestOrders as $o)
                            <tr class="border-b last:border-0">
                                <td class="py-1.5 font-mono text-xs">{{ $o->number }}</td>
                                <td class="py-1.5"><x-sales.status :status="$o->status" /></td>
                                <td class="py-1.5 text-end font-medium">{{ number_format($o->total, 2) }}</td>
                                <td class="py-1.5 text-end text-gray-400 text-xs">{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 text-gray-400">{{ __('dashboard.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ملخّص حالات التوصيل --}}
            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-3">{{ __('dashboard.delivery_summary') }}</h3>
                @if ($deliveryByStatus->isNotEmpty())
                    <div class="space-y-2">
                        @php $dmax = max(1, $deliveryByStatus->max()); @endphp
                        @foreach ($deliveryByStatus as $status => $count)
                            <div class="flex items-center gap-2 text-sm">
                                <span class="w-28 text-gray-600 shrink-0">{{ $status }}</span>
                                <div class="flex-1 bg-gray-100 rounded h-3"><div class="bg-sky-500 h-3 rounded" style="width: {{ (int) round(($count / $dmax) * 100) }}%"></div></div>
                                <span class="w-8 text-end text-gray-500">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-400">{{ __('dashboard.no_data') }}</p>
                @endif
            </div>

            {{-- الأعلى مبيعًا --}}
            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-3">{{ __('dashboard.top_products') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse ($month['top_products'] as $p)
                            <tr class="border-b last:border-0"><td class="py-1.5">{{ $p->name }}</td><td class="py-1.5 text-end">{{ number_format($p->revenue, 2) }}</td></tr>
                        @empty
                            <tr><td class="py-3 text-gray-400">{{ __('dashboard.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- أداء موظفي المبيعات --}}
            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-3">{{ __('dashboard.sales_employees') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse ($month['employees'] as $e)
                            <tr class="border-b last:border-0"><td class="py-1.5">{{ $e->name }}</td><td class="py-1.5 text-end">{{ number_format($e->sales_total, 2) }}</td><td class="py-1.5 text-end text-gray-400">{{ number_format($e->commissions, 2) }}</td></tr>
                        @empty
                            <tr><td class="py-3 text-gray-400">{{ __('dashboard.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- أصناف منخفضة المخزون --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="font-semibold text-gray-700 mb-3">{{ __('dashboard.low_stock') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @forelse ($month['low_stock'] as $item)
                    <div class="flex items-center justify-between text-sm border rounded-md px-3 py-2">
                        <span class="text-gray-700 truncate">{{ $item->name }}</span>
                        <span class="text-rose-600 font-medium">{{ rtrim(rtrim(number_format($item->on_hand, 2), '0'), '.') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('dashboard.no_data') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
