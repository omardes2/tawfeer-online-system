<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('app.dashboard') }}
                    </x-nav-link>
                    @can('viewAny', \App\Modules\Catalog\Models\Product::class)
                        <x-nav-link :href="route('admin.products.index')" :active="request()->routeIs('admin.products.*')">{{ __('المنتجات') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\Category::class)
                        <x-nav-link :href="route('admin.categories.index')" :active="request()->routeIs('admin.categories.*')">{{ __('الفئات') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\Brand::class)
                        <x-nav-link :href="route('admin.brands.index')" :active="request()->routeIs('admin.brands.*')">{{ __('العلامات') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\Unit::class)
                        <x-nav-link :href="route('admin.units.index')" :active="request()->routeIs('admin.units.*')">{{ __('الوحدات') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\ProductAttribute::class)
                        <x-nav-link :href="route('admin.attributes.index')" :active="request()->routeIs('admin.attributes.*')">{{ __('السمات') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\ProductTag::class)
                        <x-nav-link :href="route('admin.tags.index')" :active="request()->routeIs('admin.tags.*')">{{ __('الوسوم') }}</x-nav-link>
                    @endcan
                    @can('viewAny', \App\Modules\Catalog\Models\ProductReview::class)
                        <x-nav-link :href="route('admin.reviews.index')" :active="request()->routeIs('admin.reviews.*')">{{ __('التقييمات') }}</x-nav-link>
                    @endcan
                    @can('inventory.stocks.view')
                        <x-nav-link :href="route('admin.inventory.warehouse')" :active="request()->routeIs('admin.inventory.*')">{{ __('warehouse.title') }}</x-nav-link>
                    @endcan
                    @can('purchasing.suppliers.view')
                        <x-nav-link :href="route('admin.purchasing.suppliers.index')" :active="request()->routeIs('admin.purchasing.*')">{{ __('المشتريات') }}</x-nav-link>
                    @endcan
                    @can('sales.orders.view')
                        <x-nav-link :href="route('admin.sales.orders.index')" :active="request()->routeIs('admin.sales.*')">{{ __('المبيعات') }}</x-nav-link>
                    @endcan
                    @can('shipping.shipments.view')
                        <x-nav-link :href="route('admin.shipping.shipments.index')" :active="request()->routeIs('admin.shipping.shipments.*')">{{ __('الشحن') }}</x-nav-link>
                    @endcan
                    @can('shipping.delivery.view')
                        <x-nav-link :href="route('admin.shipping.delivery.index')" :active="request()->routeIs('admin.shipping.delivery.*')">{{ __('delivery.title') }}</x-nav-link>
                    @endcan
                    @can('shipping.shipments.view')
                        <x-nav-link :href="route('admin.shipping.provider_events.index')" :active="request()->routeIs('admin.shipping.provider_events.*')">{{ __('أحداث شركة التوصيل') }}</x-nav-link>
                    @endcan
                    @can('returns.view')
                        <x-nav-link :href="route('admin.returns.index')" :active="request()->routeIs('admin.returns.*')">{{ __('returns.title') }}</x-nav-link>
                    @endcan
                    @can('settlements.view')
                        <x-nav-link :href="route('admin.settlements.index')" :active="request()->routeIs('admin.settlements.*')">{{ __('settlements.title') }}</x-nav-link>
                    @endcan
                    @can('dashboard.view')
                        <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">{{ __('dashboard.title') }}</x-nav-link>
                    @endcan
                    @can('marketing.campaigns.view')
                        <x-nav-link :href="route('admin.marketing.campaigns.index')" :active="request()->routeIs('admin.marketing.*')">{{ __('التسويق') }}</x-nav-link>
                    @endcan
                    @can('recommendations.manage')
                        <x-nav-link :href="route('admin.recommendations.index')" :active="request()->routeIs('admin.recommendations.*')">{{ __('التوصيات') }}</x-nav-link>
                    @endcan
                    @can('payments.view')
                        <x-nav-link :href="route('admin.payments.index')" :active="request()->routeIs('admin.payments.*')">{{ __('المدفوعات') }}</x-nav-link>
                    @endcan
                    @can('accounting.journal.view')
                        <x-nav-link :href="route('admin.accounting.journal.index')" :active="request()->routeIs('admin.accounting.journal.*')">{{ __('المحاسبة') }}</x-nav-link>
                    @endcan
                    @can('accounting.cashboxes.view')
                        <x-nav-link :href="route('admin.accounting.cashboxes.index')" :active="request()->routeIs('admin.accounting.cashboxes.*')">{{ __('الخزائن') }}</x-nav-link>
                    @endcan
                    @can('accounting.banks.view')
                        <x-nav-link :href="route('admin.accounting.banks.index')" :active="request()->routeIs('admin.accounting.banks.*')">{{ __('البنوك') }}</x-nav-link>
                    @endcan
                    @can('accounting.receipts.view')
                        <x-nav-link :href="route('admin.accounting.vouchers.index', 'receipt')" :active="request()->routeIs('admin.accounting.vouchers.*') || request()->routeIs('admin.accounting.transfers.*')">{{ __('السندات') }}</x-nav-link>
                    @endcan
                    @can('crm.customers.view')
                        <x-nav-link :href="route('admin.crm.customers.index')" :active="request()->routeIs('admin.crm.*')">{{ __('العملاء') }}</x-nav-link>
                    @endcan
                    @can('commissions.view_team')
                        <x-nav-link :href="route('admin.commissions.index')" :active="request()->routeIs('admin.commissions.*')">{{ __('commissions.title') }}</x-nav-link>
                    @endcan
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        @can('settings.users.view')
                            <x-dropdown-link :href="route('admin.users.index')">{{ __('المستخدمون') }}</x-dropdown-link>
                        @endcan
                        @can('settings.roles.view')
                            <x-dropdown-link :href="route('admin.roles.index')">{{ __('roles.management') }}</x-dropdown-link>
                        @endcan
                        @can('settings.system.view')
                            <x-dropdown-link :href="route('admin.settings.edit')">{{ __('settings.title') }}</x-dropdown-link>
                        @endcan

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
