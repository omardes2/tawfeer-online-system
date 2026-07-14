{{--
    Admin sidebar — RTL, dark charcoal, Tawfeer-green accent.
    Permission-gated (only shows links the user can access), collapsible to
    icons-only (persisted), off-canvas drawer on mobile, active highlighting,
    expandable groups that auto-open when a child route is active.

    Alpine state (sidebarOpen, collapsed) is provided by the parent layout.
--}}
@php
    use Illuminate\Support\Facades\Gate;

    // Icon set (Heroicons outline paths) keyed by name.
    $icon = fn (string $n) => [
        'home'       => 'M2.25 12l8.954-8.955a1.5 1.5 0 012.122 0L22.5 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
        'cart'       => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'tag'        => 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z',
        'box'        => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'users'      => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'truck'      => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-6m6 0V4.5A1.5 1.5 0 0015 3h-9A1.5 1.5 0 004.5 4.5v13.5H2.25',
        'wallet'     => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3',
        'badge'      => 'M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.504-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 002.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 012.916.52 6.003 6.003 0 01-5.395 4.972m0 0a6.726 6.726 0 01-2.749 1.35m0 0a6.772 6.772 0 01-3.044 0',
        'sparkles'   => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
        'chart'      => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'cog'        => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.49l1.216.455c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'bolt'       => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
    ][$n] ?? '';

    // group: [label, icon, [items...]]; item: [route, label, can, activePattern]
    // `can` may be a permission string, or ['ability','Model::class'] for policy checks, or null (always).
    $P = fn ($m) => 'App\\Modules\\'.$m;
    $groups = [
        ['المبيعات', 'cart', [
            ['admin.sales.orders.index', 'الطلبات', ['viewAny', $P('Sales\\Models\\Order')], 'admin.sales.orders.index'],
            ['admin.sales.orders.create', 'إنشاء طلب', ['create', $P('Sales\\Models\\Order')], 'admin.sales.orders.create'],
            ['admin.shipping.shipments.index', 'الشحنات الجاهزة', 'shipping.shipments.view', 'admin.shipping.shipments.*'],
            ['admin.returns.index', 'المرتجعات والمرفوضة', 'returns.view', 'admin.returns.*'],
        ]],
        ['الكتالوج', 'box', [
            ['admin.products.index', 'المنتجات', ['viewAny', $P('Catalog\\Models\\Product')], 'admin.products.*'],
            ['admin.categories.index', 'الفئات', ['viewAny', $P('Catalog\\Models\\Category')], 'admin.categories.*'],
            ['admin.brands.index', 'العلامات التجارية', ['viewAny', $P('Catalog\\Models\\Brand')], 'admin.brands.*'],
            ['admin.tags.index', 'الوسوم', ['viewAny', $P('Catalog\\Models\\ProductTag')], 'admin.tags.*'],
            ['admin.units.index', 'وحدات القياس', ['viewAny', $P('Catalog\\Models\\Unit')], 'admin.units.*'],
            ['admin.attributes.index', 'الخيارات والمتغيّرات', ['viewAny', $P('Catalog\\Models\\ProductAttribute')], 'admin.attributes.*'],
        ]],
        ['المخزون والمشتريات', 'box', [
            ['admin.inventory.stocks', 'أرصدة المخزون', 'inventory.stocks.view', 'admin.inventory.stocks'],
            ['admin.inventory.low_stock', 'تنبيهات النقص', 'inventory.stocks.view', 'admin.inventory.low_stock'],
            ['admin.purchasing.suppliers.index', 'الموردون', 'purchasing.suppliers.view', 'admin.purchasing.suppliers.*'],
            ['admin.purchasing.invoices.index', 'فواتير الموردين', 'purchasing.invoices.view', 'admin.purchasing.invoices.*'],
            ['admin.purchasing.returns.index', 'مرتجعات الموردين', 'purchasing.returns.view', 'admin.purchasing.returns.*'],
        ]],
        ['العملاء', 'users', [
            ['admin.crm.customers.index', 'العملاء', 'crm.customers.view', 'admin.crm.customers.*'],
        ]],
        ['الشحن والتوصيل', 'truck', [
            ['admin.shipping.shipments.index', 'الشحنات', 'shipping.shipments.view', 'admin.shipping.shipments.*'],
            ['admin.shipping.delivery.index', 'محرّك التوصيل', 'shipping.delivery.view', 'admin.shipping.delivery.*'],
            ['admin.shipping.geography.index', 'مناطق الشحن', 'shipping.shipments.view', 'admin.shipping.geography.*'],
            ['admin.shipping.delivery_rates.index', 'أسعار التوصيل للمدن', 'settings.geography.view', 'admin.shipping.delivery_rates.*'],
            ['admin.returns.index', 'المرتجعات', 'returns.view', 'admin.returns.*'],
        ]],
        ['المالية والمحاسبة', 'wallet', [
            ['admin.accounting.accounts.index', 'دليل الحسابات', 'accounting.journal.view', 'admin.accounting.accounts.*'],
            ['admin.accounting.journal.index', 'القيود اليومية', 'accounting.journal.view', 'admin.accounting.journal.*'],
            ['admin.accounting.cashboxes.index', 'الخزائن النقدية', 'accounting.cashboxes.view', 'admin.accounting.cashboxes.*'],
            ['admin.accounting.banks.index', 'الحسابات البنكية', 'accounting.banks.view', 'admin.accounting.banks.*'],
            ['admin.accounting.vouchers.index', 'سندات القبض', 'accounting.receipts.view', 'admin.accounting.vouchers.*', ['receipt']],
            ['admin.accounting.vouchers.index', 'سندات الصرف', 'accounting.payments.view', 'admin.accounting.vouchers.*', ['payment']],
            ['admin.accounting.vouchers.index', 'المصروفات', 'accounting.expenses.view', 'admin.accounting.vouchers.*', ['expense']],
            ['admin.accounting.vouchers.index', 'إيرادات أخرى', 'accounting.income.view', 'admin.accounting.vouchers.*', ['income']],
            ['admin.accounting.transfers.index', 'التحويلات', 'accounting.transfers.view', 'admin.accounting.transfers.*'],
            ['admin.payments.index', 'المدفوعات', 'payments.view', 'admin.payments.*'],
            ['admin.settlements.index', 'التسويات المالية', 'settlements.view', 'admin.settlements.*'],
            ['admin.accounting.finance_reports.index', 'التقارير المالية', 'accounting.reports.view', 'admin.accounting.finance_reports.*'],
        ]],
        ['الموظفون والعمولات', 'badge', [
            ['admin.users.index', 'المستخدمون', 'settings.users.view', 'admin.users.*'],
            ['admin.roles.index', 'الأدوار والصلاحيات', 'settings.roles.view', 'admin.roles.*'],
            ['admin.commissions.index', 'العمولات والأرباح', 'commissions.view_team', 'admin.commissions.*'],
        ]],
        ['التسويق والذكاء الاصطناعي', 'sparkles', [
            ['admin.marketing.campaigns.index', 'الحملات التسويقية', 'marketing.campaigns.view', 'admin.marketing.campaigns.*'],
            ['admin.marketing.templates.index', 'قوالب الرسائل', 'marketing.campaigns.view', 'admin.marketing.templates.*'],
            ['admin.recommendations.index', 'توصيات المنتجات', 'recommendations.manage', 'admin.recommendations.*'],
        ]],
        ['التقارير', 'chart', [
            ['admin.reports.index', 'نظرة عامة', 'reports.view', 'admin.reports.index'],
            ['admin.reports.sales', 'تقارير المبيعات', 'reports.view', 'admin.reports.sales'],
            ['admin.reports.products', 'تقارير المنتجات', 'reports.view', 'admin.reports.products'],
            ['admin.reports.sales_employees', 'أداء الموظفين', 'reports.view', 'admin.reports.sales_employees'],
            ['admin.reports.delivery', 'تقارير التوصيل', 'reports.view', 'admin.reports.delivery'],
            ['admin.reports.finance', 'التقارير المالية', 'reports.view', 'admin.reports.finance'],
            ['admin.kpis', 'مؤشّرات الأداء', 'kpis.view', 'admin.kpis'],
        ]],
        ['الإعدادات', 'cog', [
            ['admin.settings.edit', 'إعدادات النظام', 'settings.system.view', 'admin.settings.*'],
        ]],
    ];

    $allows = function ($can) {
        if ($can === null) return true;
        if (is_array($can)) return Gate::allows($can[0], $can[1]);
        return Gate::allows($can);
    };
    $routeExists = fn ($name) => \Illuminate\Support\Facades\Route::has($name);
@endphp

<aside
    x-cloak
    class="fixed inset-y-0 right-0 z-40 flex flex-col bg-slate-900 text-slate-300 transition-all duration-200 ease-in-out
           ltr:right-auto ltr:left-0
           md:translate-x-0"
    :class="{
        'w-64': !collapsed, 'md:w-[76px]': collapsed,
        'translate-x-0': sidebarOpen, 'translate-x-full ltr:-translate-x-full md:translate-x-0': !sidebarOpen
    }"
    style="width:16rem"
>
    {{-- Brand --}}
    <div class="flex items-center gap-3 h-16 px-4 border-b border-white/10 shrink-0">
        <span class="grid place-items-center w-9 h-9 rounded-lg bg-emerald-500 text-white font-bold text-lg shrink-0">ت</span>
        <span class="font-bold text-white text-lg whitespace-nowrap" x-show="!collapsed" x-transition.opacity>توفير أونلاين</span>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-1 admin-scroll">
        {{-- Dashboard (single) --}}
        @can('dashboard.view')
            @php $dashActive = request()->routeIs('admin.dashboard'); @endphp
            <a href="{{ route('admin.dashboard') }}"
               @class([
                   'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                   'bg-emerald-500/15 text-white' => $dashActive,
                   'text-slate-300 hover:bg-white/5 hover:text-white' => !$dashActive,
               ])
               title="لوحة التحكّم">
                <svg class="w-5 h-5 shrink-0 {{ $dashActive ? 'text-emerald-400' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon('home') }}"/></svg>
                <span class="whitespace-nowrap" x-show="!collapsed" x-transition.opacity>لوحة التحكّم</span>
            </a>
        @endcan

        @foreach ($groups as [$label, $ic, $items])
            @php
                // Filter items to only accessible + existing routes.
                $visible = array_values(array_filter($items, fn ($it) => $routeExists($it[0]) && $allows($it[2])));
            @endphp
            @continue(count($visible) === 0)
            @php
                $isItemActive = function ($it) {
                    $active = $it[3] ?? null;
                    if (! $active || ! request()->routeIs($active)) return false;
                    // For param-scoped links (e.g. voucher kind), also match the route param.
                    if (! empty($it[4]) && request()->route('kind') !== null) {
                        return request()->route('kind') === ($it[4][0] ?? null);
                    }
                    return true;
                };
                $groupActive = collect($visible)->contains($isItemActive);
            @endphp

            <div x-data="{ open: {{ $groupActive ? 'true' : 'false' }} }" class="pt-1">
                {{-- Group header (button toggles; in collapsed mode acts as tooltip anchor) --}}
                <button type="button"
                        @click="collapsed ? (collapsed=false, open=true) : (open=!open)"
                        class="w-full group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-white/5 transition"
                        :title="collapsed ? '{{ $label }}' : ''">
                    <svg class="w-5 h-5 shrink-0 {{ $groupActive ? 'text-emerald-400' : 'text-slate-400' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($ic) }}"/></svg>
                    <span class="flex-1 text-right whitespace-nowrap" x-show="!collapsed" x-transition.opacity>{{ $label }}</span>
                    <svg class="w-4 h-4 shrink-0 transition-transform text-slate-500" x-show="!collapsed" :class="{'rotate-180': open}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>

                <div x-show="open && !collapsed" x-transition class="mt-1 space-y-0.5 pe-2 ps-3">
                    @foreach ($visible as $it)
                        @php [$r, $l] = $it; $params = $it[4] ?? []; $isActive = $isItemActive($it); @endphp
                        <a href="{{ route($r, $params) }}"
                           @class([
                               'flex items-center gap-2 rounded-md px-3 py-1.5 text-[13px] transition',
                               'bg-emerald-500/15 text-white font-medium' => $isActive,
                               'text-slate-400 hover:bg-white/5 hover:text-slate-100' => !$isActive,
                           ])>
                            <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $isActive ? 'bg-emerald-400' : 'bg-slate-600' }}"></span>
                            <span class="whitespace-nowrap">{{ $l }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    {{-- User footer --}}
    <div class="border-t border-white/10 p-3 shrink-0">
        <div class="flex items-center gap-3">
            <span class="grid place-items-center w-9 h-9 rounded-full bg-slate-700 text-emerald-300 font-semibold shrink-0">
                {{ mb_substr(auth()->user()->name, 0, 1) }}
            </span>
            <div class="min-w-0 flex-1" x-show="!collapsed" x-transition.opacity>
                <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-400 truncate">{{ auth()->user()->getRoleNames()->map(fn ($r) => trans()->has('roles.'.$r) ? __('roles.'.$r) : $r)->implode('، ') ?: __('مستخدم') }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}" x-show="!collapsed">
                @csrf
                <button type="submit" class="grid place-items-center w-8 h-8 rounded-md text-slate-400 hover:text-rose-300 hover:bg-white/5 transition" title="تسجيل الخروج">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                </button>
            </form>
        </div>
    </div>

    {{-- Collapse toggle (desktop only) --}}
    <button type="button" @click="collapsed = !collapsed; localStorage.setItem('admin_sidebar_collapsed', collapsed)"
            class="hidden md:grid place-items-center absolute top-20 -start-3 w-6 h-6 rounded-full bg-slate-800 border border-white/10 text-slate-300 hover:text-white shadow"
            title="طيّ/فتح القائمة">
        <svg class="w-3.5 h-3.5 transition-transform" :class="{'rotate-180': collapsed}" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
    </button>
</aside>
