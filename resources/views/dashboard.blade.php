<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('app.dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- بطاقة الترحيب: الدور والفرع (المبدأ 1 و12) --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="text-lg font-semibold">{{ __('app.welcome') }}، {{ auth()->user()->name }}</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-sm">
                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-100 text-emerald-800">
                            {{ __('app.role') }}:
                            @forelse (auth()->user()->getRoleNames() as $role)
                                {{ __('app.roles.'.$role) }}@if(!$loop->last)،@endif
                            @empty
                                —
                            @endforelse
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-100 text-emerald-800">
                            {{ __('app.branch') }}: {{ auth()->user()->branch?->name ?? '—' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- بطاقات الوحدات حسب الصلاحيات (المبدأ 12: RBAC، والمبدأ 14: وحدات) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @php
                    $modules = [
                        ['perm' => 'catalog.view',    'label' => 'app.modules.catalog',    'color' => 'bg-blue-500'],
                        ['perm' => 'inventory.view',  'label' => 'app.modules.inventory',  'color' => 'bg-amber-500'],
                        ['perm' => 'orders.view',     'label' => 'app.modules.orders',     'color' => 'bg-emerald-500'],
                        ['perm' => 'purchasing.view', 'label' => 'app.modules.purchasing', 'color' => 'bg-teal-500'],
                        ['perm' => 'accounting.view', 'label' => 'app.modules.accounting', 'color' => 'bg-rose-500'],
                        ['perm' => 'crm.view',        'label' => 'app.modules.crm',        'color' => 'bg-purple-500'],
                        ['perm' => 'affiliate.view',  'label' => 'app.modules.affiliate',  'color' => 'bg-cyan-500'],
                        ['perm' => 'messaging.view',  'label' => 'app.modules.messaging',  'color' => 'bg-green-500'],
                        ['perm' => 'reports.view',    'label' => 'app.modules.reports',    'color' => 'bg-slate-500'],
                    ];
                @endphp

                @foreach ($modules as $module)
                    @can($module['perm'])
                        <div class="bg-white shadow-sm rounded-lg p-5 flex items-center gap-4">
                            <span class="w-10 h-10 rounded-lg {{ $module['color'] }} opacity-80"></span>
                            <div>
                                <p class="font-medium text-gray-800">{{ __($module['label']) }}</p>
                                <p class="text-xs text-gray-500">{{ __('app.coming_soon') }}</p>
                            </div>
                        </div>
                    @endcan
                @endforeach
            </div>

            @role('admin')
                <div class="bg-white shadow-sm rounded-lg p-5">
                    <p class="font-medium text-gray-800 mb-2">{{ __('app.admin_area') }}</p>
                    <p class="text-sm text-gray-500">{{ __('app.admin_area_hint') }}</p>
                </div>
            @endrole

        </div>
    </div>
</x-app-layout>
