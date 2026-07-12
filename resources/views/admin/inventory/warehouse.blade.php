<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('warehouse.dashboard') }} — {{ $warehouse->name }}</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ([
                ['on_hand', $kpi['on_hand']],
                ['reserved', $kpi['reserved']],
                ['available', $kpi['available']],
                ['skus', $kpi['skus']],
                ['stock_value', number_format($kpi['stock_value'], 2)],
                ['damaged', $kpi['damaged']],
                ['low_stock', $kpi['low_stock']],
                ['open_counts', $kpi['open_counts']],
            ] as [$key, $val])
                <div class="bg-white shadow-sm rounded-lg border border-gray-100 p-4">
                    <p class="text-2xl font-bold text-gray-900">{{ $val }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ __('warehouse.'.$key) }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $links = [
                    ['admin.inventory.stocks', 'stocks', '📦', 'inventory.stocks.view'],
                    ['admin.inventory.movements', 'movements', '🔁', 'inventory.movements.view'],
                    ['admin.inventory.adjustments.index', 'adjustments', '⚖️', 'inventory.adjustments.view'],
                    ['admin.inventory.counts.index', 'counts', '🧮', 'inventory.counts.view'],
                    ['admin.inventory.low_stock', 'low_stock', '🔔', 'inventory.alerts.view'],
                    ['admin.inventory.batch', 'batch', '📥', 'inventory.batch'],
                ];
            @endphp
            @foreach ($links as [$route, $key, $icon, $perm])
                @can($perm)
                    <a href="{{ route($route) }}" class="bg-white shadow-sm rounded-lg border border-gray-100 p-4 hover:border-indigo-300 transition text-center">
                        <div class="text-2xl mb-1">{{ $icon }}</div>
                        <div class="text-sm font-medium text-gray-800">{{ __('warehouse.'.$key) }}</div>
                    </a>
                @endcan
            @endforeach
        </div>

        @if ($kpi['low_stock'] > 0)
            <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3">
                🔔 {{ $kpi['low_stock'] }} — <a href="{{ route('admin.inventory.low_stock') }}" class="underline">{{ __('warehouse.low_stock_title') }}</a>
            </div>
        @endif
    </div>
</x-app-layout>
