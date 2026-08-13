{{--
    تنقّل سفلي ثابت على الجوّال (يختفي على الحواسيب).
    يحترم منطقة الأمان في iPhone عبر `env(safe-area-inset-bottom)` في CSS.
--}}
@php
    $items = [
        ['route' => 'storefront.home', 'label' => __('storefront.home'), 'icon' => 'home',
         'active' => request()->routeIs('storefront.home')],
        ['route' => 'storefront.categories', 'label' => __('storefront.categories'), 'icon' => 'grid',
         'active' => request()->routeIs('storefront.categories') || request()->routeIs('storefront.category')],
        ['route' => 'storefront.search', 'label' => __('storefront.search'), 'icon' => 'search',
         'active' => request()->routeIs('storefront.search')],
        ['route' => 'storefront.cart', 'label' => __('storefront.cart'), 'icon' => 'cart',
         'active' => request()->routeIs('storefront.cart'), 'badge' => true],
        ['route' => auth()->check() ? 'account.dashboard' : 'account.login', 'label' => __('storefront.my_account'),
         'icon' => 'user', 'active' => request()->routeIs('account.*')],
    ];
@endphp

<nav class="sf-bottomnav" aria-label="{{ __('storefront.menu') }}">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}" @class(['is-active' => $item['active']])
           @if ($item['active']) aria-current="page" @endif>
            <span class="relative">
                {{-- بلا تعبئة: أيقونات مثل «البحث» و«السلة» مسارها مفتوح، فتعبئتها
                     تُنتج كتلة مصمتة. النشاط يُميَّز باللون وسماكة الخطّ. --}}
                <x-storefront.icon :name="$item['icon']"
                                   :stroke-width="$item['active'] ? '2.2' : '1.7'" />
                @if (! empty($item['badge']))
                    <span x-data x-show="$store.cart.count > 0" x-text="$store.cart.count" x-cloak
                          class="absolute -top-1.5 -end-2 min-w-[18px] h-[18px] px-1 grid place-items-center
                                 rounded-full bg-[color:var(--sf-danger)] text-white text-[10px] font-bold"></span>
                @endif
            </span>
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
