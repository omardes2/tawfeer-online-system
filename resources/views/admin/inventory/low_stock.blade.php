<x-app-layout :title="__('warehouse.low_stock_title')">
    @php
        $defaultLevel = \App\Modules\Inventory\Services\WarehouseService::defaultReorderLevel();
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    @endphp

    <x-admin.header
        :title="__('warehouse.low_stock_title')"
        :description="__('الأصناف التي نزل المتوفّر منها إلى حدّ التنبيه أو دونه — في :warehouse', ['warehouse' => $warehouse->name])"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('warehouse.low_stock_title') => null]">
        @can('settings.system.view')
            <a href="{{ route('admin.settings.edit') }}" class="btn-secondary">{{ __('ضبط الحدّ الافتراضي') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.alert tone="blue">
        {{ __('الحدّ الافتراضي الحالي: :n. يُقارَن المتوفّر بحدّ الصنف إن ضُبط له حدّ خاص، وإلا بهذا الحدّ.', ['n' => $num($defaultLevel)]) }}
    </x-admin.alert>

    <div class="mt-4">
        <x-admin.table stack>
            <thead>
                <tr>
                    <th>{{ __('warehouse.variant') }}</th>
                    <th class="text-start">{{ __('warehouse.on_hand') }}</th>
                    <th class="text-start">{{ __('warehouse.reorder_level') }}</th>
                    <th class="text-start">{{ __('warehouse.reorder_qty') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $stock)
                    @php
                        // الحدّ الفعّال يصل محسوبًا من الخدمة؛ عند غيابه نعود للسلسلة نفسها.
                        $level = $stock->effective_reorder_level
                            ?? $stock->reorder_level
                            ?? $stock->variant?->reorder_level
                            ?? $stock->variant?->product?->reorder_level
                            ?? $defaultLevel;
                    @endphp
                    <tr>
                        <td data-label="{{ __('warehouse.variant') }}">
                            <span class="font-medium text-gray-800">{{ $stock->variant?->product?->name ?? $stock->variant?->sku }}</span>
                            <span class="block text-xs text-gray-400 font-mono">{{ $stock->variant?->sku }}</span>
                        </td>
                        <td data-label="{{ __('warehouse.on_hand') }}"
                            class="text-start font-bold tabular-nums {{ (float) $stock->on_hand <= 0 ? 'text-rose-600' : 'text-amber-600' }}">
                            {{ $num($stock->on_hand) }}
                        </td>
                        <td data-label="{{ __('warehouse.reorder_level') }}" class="text-start tabular-nums text-gray-500">{{ $num($level) }}</td>
                        <td data-label="{{ __('warehouse.reorder_qty') }}" class="text-start tabular-nums text-gray-500">
                            {{ $stock->reorder_qty !== null ? $num($stock->reorder_qty) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="!p-0" data-label="">
                        <x-admin.empty-state
                            :title="__('warehouse.no_low_stock')"
                            :description="__('لا يوجد صنف نزل إلى حدّ التنبيه. ارفع الحدّ الافتراضي من الإعدادات إن أردت تنبيهًا مبكرًا.')"
                            :icon="'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'" />
                    </td></tr>
                @endforelse
            </tbody>
        </x-admin.table>
    </div>
</x-app-layout>
