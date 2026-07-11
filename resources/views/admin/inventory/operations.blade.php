<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('المخزون') }} — {{ __('العمليات') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />
        @php $variantOptions = $products->filter(fn($p) => $p->defaultVariant); @endphp

        {{-- استلام --}}
        @can('inventory.operations.receive')
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('استلام مخزون (وارد)') }}</h3>
            <form method="POST" action="{{ route('admin.inventory.operations.receive') }}" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
                @csrf
                <x-admin.field :label="__('المنتج')" name="variant">
                    <select name="variant" required class="w-full rounded-md border-gray-300 text-sm">
                        @foreach ($variantOptions as $p)<option value="{{ $p->defaultVariant->uuid }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field :label="__('المستودع')" name="warehouse">
                    <select name="warehouse" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}">{{ $wh->name }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('الكمية')" name="qty"><input type="number" step="0.001" min="0.001" name="qty" required class="w-full rounded-md border-gray-300 text-sm" /></x-admin.field>
                <x-admin.field :label="__('تكلفة الوحدة')" name="unit_cost"><input type="number" step="0.0001" min="0" name="unit_cost" required class="w-full rounded-md border-gray-300 text-sm" /></x-admin.field>
                <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('استلام') }}</button>
            </form>
        </div>
        @endcan

        {{-- صرف --}}
        @can('inventory.operations.issue')
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('صرف مخزون (صادر)') }}</h3>
            <form method="POST" action="{{ route('admin.inventory.operations.issue') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                @csrf
                <x-admin.field :label="__('المنتج')" name="variant">
                    <select name="variant" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($variantOptions as $p)<option value="{{ $p->defaultVariant->uuid }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('المستودع')" name="warehouse">
                    <select name="warehouse" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}">{{ $wh->name }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('الكمية')" name="qty"><input type="number" step="0.001" min="0.001" name="qty" required class="w-full rounded-md border-gray-300 text-sm" /></x-admin.field>
                <button class="px-4 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">{{ __('صرف') }}</button>
            </form>
        </div>
        @endcan

        {{-- تحويل --}}
        @can('inventory.operations.transfer')
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('تحويل بين مستودعين') }}</h3>
            <form method="POST" action="{{ route('admin.inventory.operations.transfer') }}" class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
                @csrf
                <x-admin.field :label="__('المنتج')" name="variant">
                    <select name="variant" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($variantOptions as $p)<option value="{{ $p->defaultVariant->uuid }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('من مستودع')" name="from_warehouse">
                    <select name="from_warehouse" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}">{{ $wh->name }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('إلى مستودع')" name="to_warehouse">
                    <select name="to_warehouse" required class="w-full rounded-md border-gray-300 text-sm">@foreach ($warehouses as $wh)<option value="{{ $wh->uuid }}">{{ $wh->name }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('الكمية')" name="qty"><input type="number" step="0.001" min="0.001" name="qty" required class="w-full rounded-md border-gray-300 text-sm" /></x-admin.field>
                <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">{{ __('تحويل') }}</button>
            </form>
        </div>
        @endcan
    </div>
</x-app-layout>
