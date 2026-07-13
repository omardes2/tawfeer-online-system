<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('reports.analytics') }}</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <p class="text-sm text-gray-500">{{ __('reports.hub_hint') }}</p>

        @php
            $dashboards = [
                ['executive', 'reports.executive', '📊'],
                ['sales', 'reports.sales_dashboard', '💰'],
                ['orders', 'reports.orders_dashboard', '🧾'],
                ['delivery', 'reports.delivery_dashboard', '🚚'],
                ['finance', 'reports.finance_dashboard', '🏦'],
            ];
            $list = [
                ['sales_employees', 'reports.sales_employees', '👤'],
                ['marketers', 'reports.marketers', '📣'],
                ['products', 'reports.products', '📦'],
                ['customers', 'reports.customers', '🧑‍🤝‍🧑'],
                ['delivery_companies', 'reports.delivery_companies', '🏢'],
                ['returns', 'reports.returns', '↩️'],
            ];
        @endphp

        <div>
            <h3 class="text-sm font-semibold text-gray-600 mb-2">{{ __('reports.dashboards') }}</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                @foreach ($dashboards as [$route, $key, $icon])
                    <a href="{{ route('admin.reports.'.$route) }}" class="bg-white shadow-sm rounded-lg border border-gray-100 p-4 hover:border-emerald-300 transition">
                        <div class="text-2xl mb-1">{{ $icon }}</div>
                        <div class="text-sm font-medium text-gray-800">{{ __($key) }}</div>
                    </a>
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-gray-600 mb-2">{{ __('reports.reports_list') }}</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                @foreach ($list as [$route, $key, $icon])
                    <a href="{{ route('admin.reports.'.$route) }}" class="bg-white shadow-sm rounded-lg border border-gray-100 p-4 hover:border-emerald-300 transition">
                        <div class="text-2xl mb-1">{{ $icon }}</div>
                        <div class="text-sm font-medium text-gray-800">{{ __($key) }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
