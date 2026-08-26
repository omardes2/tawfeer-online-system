<x-app-layout :title="__('dashboard.title')">
    @php
        // الأرقام تصل جاهزة من المتحكّم؛ هنا العرض فقط.
        $count = fn (...$s) => (int) collect($s)->sum(fn ($k) => (int) ($byStatus[$k] ?? 0));

        $ops = [
            ['label' => __('مبيعات اليوم'), 'value' => $todaySales, 'money' => true, 'tone' => 'green',
             'icon' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z'],
            ['label' => __('طلبات جديدة اليوم'), 'value' => $todayOrders, 'tone' => 'blue',
             'icon' => 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z'],
            /*
             * مبيعات اليوم مفصولةً بدل عدّادَي «قيد التجهيز» و«جاهزة للشحن».
             *
             * العدّادان كانا يقيسان مرحلةً في مسار الطلب — وهي في الصندوق
             * الموحّد وشاشة الطلبات أصلًا. أمّا **من باع كم اليوم** فلم يكن
             * ظاهرًا إلّا بفتح جدولٍ في أسفل الصفحة.
             *
             * ولمن يرى أداء الفريق وحده: مبيعات الزملاء لا تُعرض لموظفٍ على
             * زميله (المبدأ 11 — بالصلاحية لا بالاسم).
             */
            ...(\Illuminate\Support\Facades\Gate::allows('commissions.view_team') ? [
                ['label' => __('مبيعات الموظفين اليوم'), 'value' => $todayByEarner['staff'], 'money' => true, 'tone' => 'blue',
                 'hint' => __('بلا رسوم التوصيل'),
                 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
                ['label' => __('مبيعات المسوّقين اليوم'), 'value' => $todayByEarner['affiliates'], 'money' => true, 'tone' => 'green',
                 'hint' => __('بلا رسوم التوصيل'),
                 'icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z'],
            ] : [
                ['label' => __('قيد التجهيز'), 'value' => $count('processing'), 'tone' => 'amber',
                 'icon' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z'],
                ['label' => __('جاهزة للشحن'), 'value' => $count('confirmed'), 'tone' => 'blue',
                 'icon' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z'],
            ]),
            ['label' => __('قيد التوصيل'), 'value' => $count('shipped'), 'tone' => 'blue',
             'icon' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-6m6 0V4.5A1.5 1.5 0 0015 3h-9A1.5 1.5 0 004.5 4.5v13.5H2.25'],
            ['label' => __('مُسلّمة'), 'value' => $count('delivered'), 'tone' => 'green',
             'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['label' => __('مرتجعة/ملغاة'), 'value' => $count('cancelled', 'returned'), 'tone' => 'red',
             'icon' => 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3'],
            ['label' => __('أصناف منخفضة'), 'value' => $warehouse['low_stock'] ?? ($month['low_stock']->count() ?? 0), 'tone' => 'amber',
             'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
        ];
    @endphp

    <x-admin.header
        :title="__('dashboard.title')"
        :description="__('نظرة عامة على أداء المتجر والعمليات اليومية')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('dashboard.title') => null]">
    </x-admin.header>

    {{-- Operations KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4">
        @foreach ($ops as $c)
            <x-admin.stat-card :label="$c['label']" :value="$c['value']" :tone="$c['tone']" :icon="$c['icon']" :money="$c['money'] ?? false" />
        @endforeach
    </div>

    {{-- Finance (only for finance-permitted roles) --}}
    @can('accounting.reports.view')
        @isset($finance)
            <div class="mt-6">
                <h2 class="text-sm font-semibold text-gray-500 mb-3">{{ __('الملخّص المالي') }}</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                    <x-admin.stat-card :label="__('إجمالي الخزائن')" :value="$finance['cashbox_total']" money tone="green" />
                    <x-admin.stat-card :label="__('إجمالي البنوك')" :value="$finance['bank_total']" money tone="blue" />
                    <x-admin.stat-card :label="__('قبض اليوم')" :value="$finance['today_receipts']" money tone="green" />
                    <x-admin.stat-card :label="__('صرف اليوم')" :value="$finance['today_payments']" money tone="red" />
                    <x-admin.stat-card :label="__('المبالغ المُحصّلة (شهر)')" :value="$month['sales']['collected']" money tone="green" />
                    @if ($pendingCommissions !== null)
                        {{-- المستحقّ الآن، وتحته ما لم يُستحقّ بعد — فلا يظنّ
                             القارئ أن الفرق ضاع. --}}
                        <x-admin.stat-card :label="__('عمولات مستحقّة')" :value="$pendingCommissions" money tone="amber"
                                           :hint="($notYetDueCommissions ?? 0) > 0
                                               ? __('و :v قيد التحصيل لم تُستحقّ بعد', ['v' => number_format($notYetDueCommissions, 2)])
                                               : __('استُحقّت ولم تُصرف')" />
                    @endif
                </div>
            </div>
        @endisset
    @endcan

    {{-- Sales chart + order status overview --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6">
        <div class="admin-card admin-card-pad lg:col-span-2">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-semibold text-gray-800">{{ __('مبيعات السنة (شهريًّا)') }}</h3>
                    {{-- الإجمالي بلا رسوم التوصيل: هو ما يدخل الدفاتر. --}}
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ __('الإجمالي') }}:
                        <span class="font-bold text-gray-800 tabular-nums">{{ number_format($monthlySales->sum('total'), 2) }}</span>
                        <span class="text-gray-400">{{ __('بلا رسوم التوصيل') }}</span>
                    </p>
                </div>
                <span class="text-xs text-gray-400">{{ $chartYear }}</span>
            </div>
            {{--
                اثنا عشر شهرًا دائمًا ولو كان بعضها صفرًا: رسمٌ يحذف الشهور
                الفارغة يُظهر تمّوزًا بجانب تشرين فيبدو النمو متّصلًا وهو منقطع.
            --}}
            @php $max = max(1, (float) $monthlySales->max('total')); @endphp
            <div class="flex items-end gap-1.5 h-56">
                @foreach ($monthlySales as $m)
                    <div class="group relative flex flex-col items-center justify-end flex-1 min-w-0">
                        {{-- القيمة فوق العمود: قراءتها لا تحتاج تمرير المؤشّر. --}}
                        <span class="text-[9px] text-gray-500 tabular-nums mb-1 whitespace-nowrap {{ $m['total'] > 0 ? '' : 'invisible' }}">
                            {{ $m['total'] >= 1000 ? number_format($m['total'] / 1000, 1).'k' : number_format($m['total'], 0) }}
                        </span>
                        <div class="w-full rounded-t transition-all {{ $m['total'] > 0 ? 'bg-emerald-500 group-hover:bg-emerald-600' : 'bg-gray-100' }}"
                             style="height: {{ $m['total'] > 0 ? max(3, (int) round(($m['total'] / $max) * 100)) : 2 }}%"
                             title="{{ $m['label'] }}: {{ number_format($m['total'], 2) }}"></div>
                        <span class="text-[10px] text-gray-400 mt-1">{{ $m['month'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="admin-card admin-card-pad">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('نظرة على حالات الطلبات') }}</h3>
            @php $omax = max(1, $byStatus->max() ?? 1); @endphp
            @if ($byStatus->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($byStatus as $status => $c)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="w-20 shrink-0"><x-sales.status :status="$status" /></span>
                            <div class="flex-1 bg-gray-100 rounded-full h-2"><div class="bg-emerald-500 h-2 rounded-full" style="width: {{ (int) round(($c / $omax) * 100) }}%"></div></div>
                            <span class="w-8 text-end text-gray-600 tabular-nums">{{ $c }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-admin.empty-state :title="__('dashboard.no_data')" />
            @endif
        </div>
    </div>

    {{-- Latest orders + delivery status --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
        <x-admin.table :title="__('dashboard.latest_orders')">
            <thead><tr><th>{{ __('رقم الطلب') }}</th><th>{{ __('الحالة') }}</th><th class="text-start">{{ __('الإجمالي') }}</th><th class="text-start">{{ __('التاريخ') }}</th></tr></thead>
            <tbody>
                @forelse ($latestOrders as $o)
                    <tr>
                        <td class="font-mono text-xs">{{ $o->number }}</td>
                        <td><x-sales.status :status="$o->status" /></td>
                        <td class="text-start font-medium tabular-nums">{{ number_format($o->total, 2) }}</td>
                        <td class="text-start text-gray-400 text-xs">{{ \Illuminate\Support\Carbon::parse($o->created_at)->format('m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="!p-0"><x-admin.empty-state :title="__('لا توجد طلبات بعد')" /></td></tr>
                @endforelse
            </tbody>
        </x-admin.table>

        <div class="admin-card admin-card-pad">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('dashboard.delivery_summary') }}</h3>
            @if ($deliveryByStatus->isNotEmpty())
                <div class="space-y-3">
                    @php $dmax = max(1, $deliveryByStatus->max()); @endphp
                    @foreach ($deliveryByStatus as $status => $c)
                        <div class="flex items-center gap-2 text-sm">
                            {{-- مفتاح الترجمة الصحيح `delivery.status.*`؛ الخاطئ كان يعرض المفتاح الإنجليزي الخام --}}
                            <span class="w-32 text-gray-600 shrink-0 truncate">{{ trans()->has('delivery.status.'.$status) ? __('delivery.status.'.$status) : $status }}</span>
                            <div class="flex-1 bg-gray-100 rounded-full h-2"><div class="bg-sky-500 h-2 rounded-full" style="width: {{ (int) round(($c / $dmax) * 100) }}%"></div></div>
                            <span class="w-8 text-end text-gray-600 tabular-nums">{{ $c }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-admin.empty-state :title="__('لا توجد شحنات بعد')" :description="__('ستظهر حالات التوصيل هنا عند إنشاء أول شحنة.')" />
            @endif
        </div>
    </div>

    {{-- Warehouse summary --}}
    <div class="mt-6 admin-card admin-card-pad">
        <h3 class="font-semibold text-gray-800 mb-4">{{ __('dashboard.warehouse') }}</h3>
        @if ($warehouse)
            <dl class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div><dt class="text-sm text-gray-500">{{ __('dashboard.skus') }}</dt><dd class="mt-1 text-xl font-bold text-gray-900 tabular-nums">{{ $warehouse['skus'] }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('dashboard.available') }}</dt><dd class="mt-1 text-xl font-bold text-gray-900 tabular-nums">{{ rtrim(rtrim(number_format($warehouse['available'], 2), '0'), '.') }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('dashboard.reserved') }}</dt><dd class="mt-1 text-xl font-bold text-gray-900 tabular-nums">{{ rtrim(rtrim(number_format($warehouse['reserved'], 2), '0'), '.') }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('dashboard.low_stock') }}</dt><dd class="mt-1 text-xl font-bold text-rose-600 tabular-nums">{{ $warehouse['low_stock'] }}</dd></div>
                <div><dt class="text-sm text-gray-500">{{ __('dashboard.stock_value') }}</dt><dd class="mt-1 text-xl font-bold text-emerald-600 tabular-nums">{{ number_format($warehouse['stock_value'], 2) }}</dd></div>
            </dl>
        @else
            <x-admin.empty-state :title="__('dashboard.warehouse')" :description="__('dashboard.no_data')" />
        @endif
    </div>

    {{-- Best sellers + staff performance --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
        <x-admin.table :title="__('dashboard.top_products')">
            <thead><tr><th>{{ __('المنتج') }}</th><th class="text-start">{{ __('الإيراد') }}</th></tr></thead>
            <tbody>
                @forelse ($month['top_products'] as $p)
                    <tr><td class="text-gray-800">{{ $p->name }}</td><td class="text-start font-medium tabular-nums">{{ number_format($p->revenue, 2) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="!p-0"><x-admin.empty-state :title="__('dashboard.no_data')" /></td></tr>
                @endforelse
            </tbody>
        </x-admin.table>

        <x-admin.table :title="__('dashboard.sales_employees')">
            <thead><tr><th>{{ __('الموظف') }}</th><th class="text-start">{{ __('المبيعات') }}</th><th class="text-start">{{ __('العمولة') }}</th></tr></thead>
            <tbody>
                @forelse ($month['employees'] as $e)
                    <tr><td class="text-gray-800">{{ $e->name }}</td><td class="text-start font-medium tabular-nums">{{ number_format($e->sales_total, 2) }}</td><td class="text-start text-gray-400 tabular-nums">{{ number_format($e->commissions, 2) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="!p-0"><x-admin.empty-state :title="__('dashboard.no_data')" :description="__('لا توجد بيانات أداء ضمن هذه الفترة.')" /></td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
    </div>

    {{-- أداء موظفي المبيعات والمسوّقين: اليوم / أمس / الشهر --}}
    @can('reports.sales_summary.view')
        @foreach ([
            ['rows' => $salesBoard, 'title' => __('أداء موظفي المبيعات'), 'person' => __('موظف المبيعات'),
             'empty' => __('لم يُسجَّل موظفو مبيعات بعد.')],
            ['rows' => $affiliateBoard, 'title' => __('أداء المسوّقين'), 'person' => __('المسوّق'),
             'empty' => __('لم يُسجَّل مسوّقون بعد.')],
        ] as $board)
            <div class="mt-6">
                <x-admin.table :title="$board['title']" stack>
                    <thead>
                        <tr>
                            <th>{{ $board['person'] }}</th>
                            <th class="text-start">{{ __('طلبيات اليوم') }}</th>
                            <th class="text-start">{{ __('مبيعات اليوم') }}</th>
                            <th class="text-start">{{ __('مبيعات أمس') }}</th>
                            <th class="text-start">{{ __('مبيعات الشهر') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($board['rows'] as $row)
                            <tr>
                                <td data-label="{{ $board['person'] }}" class="font-medium text-gray-800">{{ $row['name'] }}</td>
                                <td data-label="{{ __('طلبيات اليوم') }}" class="text-start tabular-nums">{{ $row['orders_today'] }}</td>
                                <td data-label="{{ __('مبيعات اليوم') }}" class="text-start tabular-nums font-medium {{ $row['sales_today'] > 0 ? 'text-emerald-700' : 'text-gray-400' }}">{{ number_format($row['sales_today'], 2) }}</td>
                                <td data-label="{{ __('مبيعات أمس') }}" class="text-start tabular-nums {{ $row['sales_yesterday'] > 0 ? 'text-gray-700' : 'text-gray-400' }}">{{ number_format($row['sales_yesterday'], 2) }}</td>
                                <td data-label="{{ __('مبيعات الشهر') }}" class="text-start tabular-nums font-semibold {{ $row['sales_month'] > 0 ? 'text-gray-900' : 'text-gray-400' }}">{{ number_format($row['sales_month'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="!p-0" data-label="">
                                <x-admin.empty-state :title="__('dashboard.no_data')" :description="$board['empty']" />
                            </td></tr>
                        @endforelse
                    </tbody>

                    @if ($board['rows']->isNotEmpty())
                        <tfoot>
                            <tr class="bg-gray-50 font-semibold text-gray-800">
                                <td data-label="">{{ __('الإجمالي') }}</td>
                                <td data-label="{{ __('طلبيات اليوم') }}" class="text-start tabular-nums">{{ $board['rows']->sum('orders_today') }}</td>
                                <td data-label="{{ __('مبيعات اليوم') }}" class="text-start tabular-nums">{{ number_format($board['rows']->sum('sales_today'), 2) }}</td>
                                <td data-label="{{ __('مبيعات أمس') }}" class="text-start tabular-nums">{{ number_format($board['rows']->sum('sales_yesterday'), 2) }}</td>
                                <td data-label="{{ __('مبيعات الشهر') }}" class="text-start tabular-nums">{{ number_format($board['rows']->sum('sales_month'), 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </x-admin.table>
                <p class="mt-2 text-xs text-gray-400">{{ __('المبالغ بدون رسوم التوصيل — نفس الأساس الذي تُحتسب عليه العمولات وتدخل به الدفاتر.') }}</p>
            </div>
        @endforeach
    @endcan

    {{-- Low-stock products --}}
    <div class="mt-6 admin-card admin-card-pad">
        <h3 class="font-semibold text-gray-800 mb-4">{{ __('dashboard.low_stock') }}</h3>
        @if ($month['low_stock']->isNotEmpty())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($month['low_stock'] as $item)
                    <div class="flex items-center justify-between gap-2 text-sm border border-gray-200 rounded-lg px-3 py-2">
                        <span class="text-gray-700 truncate">{{ $item->name }}</span>
                        <x-admin.badge tone="red" :label="rtrim(rtrim(number_format($item->on_hand, 2), '0'), '.')" :icon="false" />
                    </div>
                @endforeach
            </div>
        @else
            <x-admin.empty-state :title="__('المخزون بحالة جيدة')" :description="__('لا توجد أصناف تحت حدّ إعادة الطلب حاليًا.')"
                :icon="'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'" />
        @endif
    </div>
</x-app-layout>
